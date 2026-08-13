/**
 * support-chat visitor widget
 */
(function () {
    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        function initChatWidget() {
            const root = document.querySelector('[data-sc-chat]');
            if (!root) return;

            let i18n = {};
            let endpoints = {};
            try {
                i18n = JSON.parse(root.dataset.i18n || '{}');
            } catch {
                i18n = {};
            }
            try {
                endpoints = JSON.parse(root.dataset.endpoints || '{}');
            } catch {
                endpoints = {};
            }

            const LEAD_KEY = 'sc-chat-lead';
            const panel = root.querySelector('[data-sc-chat-panel]');
            const leadView = root.querySelector('[data-sc-chat-lead]');
            const leadForm = root.querySelector('[data-sc-chat-lead-form]');
            const leadError = root.querySelector('[data-sc-chat-lead-error]');
            const composeError = root.querySelector('[data-sc-chat-compose-error]');
            const nameInput = root.querySelector('[data-sc-chat-lead-name]');
            const emailInput = root.querySelector('[data-sc-chat-lead-email]');
            const phoneInput = root.querySelector('[data-sc-chat-lead-phone]');
            const conversationEl = root.querySelector('[data-sc-chat-conversation]');
            const thread = root.querySelector('[data-sc-chat-thread]');
            const chips = root.querySelector('[data-sc-chat-chips]');
            const form = root.querySelector('[data-sc-chat-form]');
            const input = root.querySelector('[data-sc-chat-input]');
            const sendBtn = root.querySelector('[data-sc-chat-send]');
            const fileInput = root.querySelector('[data-sc-chat-file]');
            const fileChip = root.querySelector('[data-sc-chat-file-chip]');
            const fileNameEl = root.querySelector('[data-sc-chat-file-name]');
            const fileClear = root.querySelector('[data-sc-chat-file-clear]');
            const replyBar = root.querySelector('[data-sc-chat-reply-bar]');
            const replyLabel = root.querySelector('[data-sc-chat-reply-label]');
            const replySnippet = root.querySelector('[data-sc-chat-reply-snippet]');
            const replyClear = root.querySelector('[data-sc-chat-reply-clear]');
            const launcher = root.querySelector('[data-sc-chat-launcher]');
            const badgeEl = root.querySelector('[data-sc-chat-badge]');
            const closeBtn = root.querySelector('[data-sc-chat-close]');
            const iconOpen = root.querySelector('.sc-chat__launcher-icon--open');
            const iconClose = root.querySelector('.sc-chat__launcher-icon--close');
            const launcherCopy = root.querySelector('.sc-chat__launcher-copy');
            const leadSubmit = root.querySelector('[data-sc-chat-lead-submit]');

            if (!panel || !leadForm || !conversationEl || !thread || !form || !input || !launcher) return;
            if (!endpoints.session || !endpoints.start || !endpoints.messages) return;

            const ALLOWED_EXTS = new Set(['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp']);
            const MAX_ATTACH_BYTES = 5 * 1024 * 1024;

            let open = false;
            let lead = null;
            let lastMessageId = 0;
            let pollTimer = null;
            let sending = false;
            let pendingFile = null;
            let replyTo = null;
            let typingRow = null;
            let typingLastSent = 0;
            let typingInFlight = false;
            let pollBackoffUntil = 0;
            let unread = 0;
            let audioCtx = null;
            const seenIds = new Set();
            const POLL_OPEN_MS = 3000;
            const POLL_CLOSED_MS = 15000;
            const originalTitle = document.title;

            const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            const api = async (url, options = {}) => {
                const headers = {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(options.headers || {}),
                };
                const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
                if (options.body && !isFormData && !headers['Content-Type']) {
                    headers['Content-Type'] = 'application/json';
                }
                const token = csrf();
                if (token) headers['X-CSRF-TOKEN'] = token;

                const res = await fetch(url, {
                    credentials: 'same-origin',
                    ...options,
                    headers,
                });

                let payload = null;
                try {
                    payload = await res.json();
                } catch {
                    payload = null;
                }

                if (!res.ok) {
                    const message =
                        payload?.message
                        || payload?.errors?.phone?.[0]
                        || payload?.errors?.email?.[0]
                        || payload?.errors?.name?.[0]
                        || payload?.errors?.body?.[0]
                        || payload?.errors?.attachment?.[0]
                        || i18n.networkError
                        || 'Request failed.';
                    const error = new Error(message);
                    error.status = res.status;
                    throw error;
                }

                return payload;
            };

            const showLeadError = (message) => {
                if (!leadError) return;
                if (!message) {
                    leadError.hidden = true;
                    leadError.textContent = '';
                    return;
                }
                leadError.hidden = false;
                leadError.textContent = message;
            };

            const showComposeError = (message) => {
                if (!composeError) return;
                if (!message) {
                    composeError.hidden = true;
                    composeError.textContent = '';
                    return;
                }
                composeError.hidden = false;
                composeError.textContent = message;
            };

            const setConversationUnlocked = (unlocked) => {
                root.classList.toggle('is-identified', unlocked);
                if (leadView) leadView.hidden = unlocked;
                conversationEl.hidden = !unlocked;
            };

            const cacheLead = (next) => {
                lead = next;
                try {
                    sessionStorage.setItem(LEAD_KEY, JSON.stringify(next));
                } catch {
                    // ignore
                }
                if (nameInput) nameInput.value = next.name || '';
                if (emailInput) emailInput.value = next.email || '';
                if (phoneInput) phoneInput.value = next.phone || '';
            };

            const readCachedLead = () => {
                try {
                    const raw = sessionStorage.getItem(LEAD_KEY);
                    if (!raw) return null;
                    const parsed = JSON.parse(raw);
                    if (parsed?.name && parsed?.email && parsed?.phone) {
                        return {
                            name: String(parsed.name).trim(),
                            email: String(parsed.email).trim(),
                            phone: String(parsed.phone).trim(),
                        };
                    }
                } catch {
                    // ignore
                }
                return null;
            };

            const validateLead = () => {
                const name = (nameInput?.value || '').trim();
                const email = (emailInput?.value || '').trim();
                const phone = (phoneInput?.value || '').trim();

                if (!name || !email || !phone) {
                    return { ok: false, message: i18n.leadRequired || 'Please fill all fields.' };
                }
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    return { ok: false, message: i18n.leadInvalidEmail || 'Enter a valid email.' };
                }
                const digits = phone.replace(/\D+/g, '');
                if (digits.length < 8 || digits.length > 15) {
                    return { ok: false, message: i18n.leadInvalidPhone || 'Enter a valid mobile number.' };
                }

                return { ok: true, lead: { name, email, phone } };
            };

            const scrollThread = () => {
                thread.scrollTop = thread.scrollHeight;
            };

            const roleFromSender = (sender) => (sender === 'visitor' ? 'user' : 'agent');

            const whoLabel = (sender) => {
                if (sender === 'visitor') return i18n.you || 'You';
                if (sender === 'system') return i18n.agent || 'Desk';
                return i18n.agent || 'Desk';
            };

            const formatBytes = (bytes) => {
                const n = Number(bytes) || 0;
                if (n < 1024) return `${n} B`;
                if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
                return `${(n / (1024 * 1024)).toFixed(1)} MB`;
            };

            const clearPendingFile = () => {
                pendingFile = null;
                if (fileInput) fileInput.value = '';
                if (fileChip) fileChip.hidden = true;
                if (fileNameEl) fileNameEl.textContent = '';
                syncSend();
            };

            const setPendingFile = (file) => {
                if (!file) {
                    clearPendingFile();
                    return;
                }

                const ext = String(file.name || '').split('.').pop()?.toLowerCase() || '';
                if (!ALLOWED_EXTS.has(ext) || /\.(php|phtml|phar|exe|sh|bat|cmd|js|html|htm|svg)(\.|$)/i.test(file.name)) {
                    clearPendingFile();
                    showComposeError(i18n.attachBadType || 'File type not allowed.');
                    return;
                }
                if (file.size <= 0 || file.size > MAX_ATTACH_BYTES) {
                    clearPendingFile();
                    showComposeError(i18n.attachTooBig || 'File is too large.');
                    return;
                }

                showComposeError('');
                pendingFile = file;
                if (fileNameEl) fileNameEl.textContent = `${file.name} · ${formatBytes(file.size)}`;
                if (fileChip) fileChip.hidden = false;
                syncSend();
            };

            const clearReplyTo = () => {
                replyTo = null;
                if (replyBar) replyBar.hidden = true;
                if (replyLabel) replyLabel.textContent = '';
                if (replySnippet) replySnippet.textContent = '';
            };

            const setReplyTo = (msg) => {
                if (!msg?.id) return;
                const sender = msg.sender || 'agent';
                const who = whoLabel(sender);
                let snippet = String(msg.body || '').trim();
                if (!snippet && msg.attachment?.name) {
                    snippet = msg.attachment.name;
                }
                if (snippet.length > 90) snippet = `${snippet.slice(0, 87)}…`;

                replyTo = {
                    id: Number(msg.id),
                    sender,
                    body: String(msg.body || '').trim(),
                    attachment: msg.attachment || null,
                };

                if (replyLabel) {
                    replyLabel.textContent = `${i18n.replyingTo || 'Replying to'} ${who}`;
                }
                if (replySnippet) replySnippet.textContent = snippet || '—';
                if (replyBar) replyBar.hidden = false;
                input.focus();
            };

            const isImageAttachment = (attachment) => {
                const mime = String(attachment?.mime || '').toLowerCase();
                return mime.startsWith('image/');
            };

            const buildAttachmentCard = (attachment) => {
                const card = document.createElement('div');
                card.className = 'sc-chat__file-card';

                if (isImageAttachment(attachment) && attachment.preview_url) {
                    const img = document.createElement('img');
                    img.className = 'sc-chat__file-thumb';
                    img.src = attachment.preview_url;
                    img.alt = attachment.name || '';
                    img.loading = 'lazy';
                    card.append(img);
                } else {
                    const icon = document.createElement('div');
                    icon.className = 'sc-chat__file-icon';
                    icon.setAttribute('aria-hidden', 'true');
                    icon.textContent = '📄';
                    card.append(icon);
                }

                const meta = document.createElement('div');
                meta.className = 'sc-chat__file-meta';

                const name = document.createElement('p');
                name.className = 'sc-chat__file-name';
                name.textContent = attachment.name || 'file';

                const size = document.createElement('p');
                size.className = 'sc-chat__file-size';
                size.textContent = attachment.size ? formatBytes(attachment.size) : '';

                meta.append(name);
                if (attachment.size) meta.append(size);
                card.append(meta);

                if (attachment.url) {
                    const download = document.createElement('a');
                    download.className = 'sc-chat__file-download';
                    download.href = attachment.url;
                    download.rel = 'noopener noreferrer';
                    download.download = '';
                    download.textContent = i18n.download || 'Download';
                    card.append(download);
                }

                return card;
            };

            const buildReplyQuote = (reply) => {
                if (!reply) return null;
                const quote = document.createElement('button');
                quote.type = 'button';
                quote.className = 'sc-chat__quote';
                quote.setAttribute('aria-label', i18n.reply || 'Reply');

                const who = document.createElement('span');
                who.className = 'sc-chat__quote-who';
                who.textContent = whoLabel(reply.sender);

                const text = document.createElement('span');
                text.className = 'sc-chat__quote-text';
                let snippet = String(reply.body || '').trim();
                if (!snippet && reply.attachment?.name) snippet = reply.attachment.name;
                text.textContent = snippet || '—';

                quote.append(who, text);
                return quote;
            };

            const bindSwipeReply = (row, msg) => {
                if (!msg?.id || msg.sender === 'system') return;

                let startX = 0;
                let startY = 0;
                let dragging = false;
                let dx = 0;
                const bubble = row.querySelector('.sc-chat__bubble');
                if (!bubble) return;

                const onStart = (clientX, clientY) => {
                    startX = clientX;
                    startY = clientY;
                    dragging = true;
                    dx = 0;
                    bubble.style.transition = 'none';
                };

                const onMove = (clientX, clientY) => {
                    if (!dragging) return;
                    const mx = clientX - startX;
                    const my = clientY - startY;
                    if (Math.abs(my) > Math.abs(mx) && Math.abs(my) > 8) {
                        dragging = false;
                        bubble.style.transform = '';
                        return;
                    }
                    dx = Math.max(0, Math.min(72, mx));
                    bubble.style.transform = `translateX(${dx}px)`;
                    row.classList.toggle('is-swiping', dx > 8);
                };

                const onEnd = () => {
                    if (!dragging) return;
                    dragging = false;
                    bubble.style.transition = 'transform 0.18s ease';
                    if (dx >= 48) {
                        setReplyTo(msg);
                    }
                    bubble.style.transform = '';
                    row.classList.remove('is-swiping');
                    dx = 0;
                };

                row.addEventListener('touchstart', (e) => {
                    if (e.touches.length !== 1) return;
                    onStart(e.touches[0].clientX, e.touches[0].clientY);
                }, { passive: true });
                row.addEventListener('touchmove', (e) => {
                    if (!dragging || e.touches.length !== 1) return;
                    onMove(e.touches[0].clientX, e.touches[0].clientY);
                }, { passive: true });
                row.addEventListener('touchend', onEnd);
                row.addEventListener('touchcancel', onEnd);
            };

            const appendMessage = (msg) => {
                const id = Number(msg.id || 0);
                if (id && seenIds.has(id)) return;
                if (id) {
                    seenIds.add(id);
                    lastMessageId = Math.max(lastMessageId, id);
                }

                const role = roleFromSender(msg.sender || (msg.role === 'user' ? 'visitor' : 'agent'));
                const row = document.createElement('div');
                row.className = `sc-chat__row sc-chat__row--${role}`;

                const bubble = document.createElement('div');
                bubble.className = 'sc-chat__bubble';
                bubble.dataset.role = role;

                const head = document.createElement('div');
                head.className = 'sc-chat__bubble-head';

                const who = document.createElement('p');
                who.className = 'sc-chat__who';
                who.textContent = whoLabel(msg.sender || (role === 'user' ? 'visitor' : 'agent'));
                head.append(who);

                if (msg.sender !== 'system' && msg.id) {
                    const replyBtn = document.createElement('button');
                    replyBtn.type = 'button';
                    replyBtn.className = 'sc-chat__reply-btn';
                    replyBtn.textContent = i18n.reply || 'Reply';
                    replyBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        setReplyTo(msg);
                    });
                    head.append(replyBtn);
                }

                bubble.append(head);

                const quote = buildReplyQuote(msg.reply_to);
                if (quote) bubble.append(quote);

                const text = String(msg.body || msg.text || '').trim();
                if (text) {
                    const body = document.createElement('p');
                    body.className = 'sc-chat__text';
                    body.textContent = text;
                    bubble.append(body);
                }

                const attachment = msg.attachment;
                if (attachment?.name) {
                    bubble.append(buildAttachmentCard(attachment));
                }

                row.append(bubble);
                if (typingRow && typingRow.parentNode === thread) {
                    thread.insertBefore(row, typingRow);
                } else {
                    thread.append(row);
                }

                bindSwipeReply(row, msg);
                scrollThread();
            };

            const setAgentTyping = (isTyping) => {
                if (isTyping) {
                    if (typingRow) return;
                    typingRow = document.createElement('div');
                    typingRow.className = 'sc-chat__row sc-chat__row--agent';

                    const bubble = document.createElement('div');
                    bubble.className = 'sc-chat__bubble sc-chat__bubble--typing';
                    bubble.dataset.role = 'agent';

                    const dots = document.createElement('span');
                    dots.className = 'sc-chat__dots';
                    dots.setAttribute('aria-hidden', 'true');
                    dots.append(document.createElement('i'), document.createElement('i'), document.createElement('i'));

                    const sr = document.createElement('span');
                    sr.className = 'sr-only';
                    sr.textContent = i18n.typing || 'Agent is typing';

                    bubble.append(dots, sr);
                    typingRow.append(bubble);
                    thread.append(typingRow);
                    scrollThread();
                    return;
                }

                if (typingRow) {
                    typingRow.remove();
                    typingRow = null;
                }
            };

            const pingTyping = () => {
                if (!lead || !open || sending || !endpoints.typing) return;
                if (input.value.trim() === '') return;
                const now = Date.now();
                if (typingInFlight || now - typingLastSent < 2500) return;
                typingLastSent = now;
                typingInFlight = true;
                api(endpoints.typing, { method: 'POST' })
                    .catch(() => {})
                    .finally(() => {
                        typingInFlight = false;
                    });
            };

            const renderHistory = (messages) => {
                thread.innerHTML = '';
                typingRow = null;
                seenIds.clear();
                lastMessageId = 0;
                (messages || []).forEach((msg) => appendMessage(msg));
                if (chips) {
            const hasChips = chips.querySelectorAll('[data-sc-chat-chip]').length > 0;
            chips.hidden = !hasChips || (messages || []).some((m) => m.sender === 'visitor');
        }
            };

            const setUnread = (count) => {
                unread = Math.max(0, count);
                if (badgeEl) {
                    badgeEl.hidden = unread === 0;
                    badgeEl.textContent = unread > 9 ? '9+' : String(unread);
                }
                document.title = unread > 0 ? `(${unread}) ${originalTitle}` : originalTitle;
            };

            // Two-note chime via WebAudio — no asset file, no extra request.
            // Only audible after a user gesture (autoplay policy); chat users always
            // interact first, so resume() succeeds by the time a reply lands.
            const playPing = () => {
                try {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    if (!Ctx) return;
                    audioCtx = audioCtx || new Ctx();
                    if (audioCtx.state === 'suspended') {
                        audioCtx.resume().catch(() => {});
                    }
                    if (audioCtx.state !== 'running') return;
                    const t0 = audioCtx.currentTime;
                    [[880, 0], [1318.5, 0.1]].forEach(([freq, delay]) => {
                        const osc = audioCtx.createOscillator();
                        const gain = audioCtx.createGain();
                        osc.type = 'sine';
                        osc.frequency.value = freq;
                        gain.gain.setValueAtTime(0.0001, t0 + delay);
                        gain.gain.exponentialRampToValueAtTime(0.1, t0 + delay + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.0001, t0 + delay + 0.28);
                        osc.connect(gain).connect(audioCtx.destination);
                        osc.start(t0 + delay);
                        osc.stop(t0 + delay + 0.3);
                    });
                } catch {
                    // audio unavailable — ignore
                }
            };

            const stopPoll = () => {
                if (pollTimer) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
            };

            const pollMessages = async () => {
                if (!lead) return;
                if (Date.now() < pollBackoffUntil) return;
                try {
                    const url = `${endpoints.messages}?after_id=${encodeURIComponent(String(lastMessageId))}`;
                    const payload = await api(url);
                    let incoming = 0;
                    (payload.messages || []).forEach((msg) => {
                        if (msg.sender && msg.sender !== 'visitor') incoming += 1;
                        appendMessage(msg);
                    });
                    setAgentTyping(Boolean(payload?.typing?.agent));
                    if (incoming > 0) {
                        if (!open) setUnread(unread + incoming);
                        if (!open || document.hidden) playPing();
                    }
                } catch (error) {
                    if (error?.status === 429) {
                        pollBackoffUntil = Date.now() + 30000;
                    }
                    // keep polling silently
                }
            };

            const startPoll = (interval = POLL_OPEN_MS) => {
                stopPoll();
                pollTimer = window.setInterval(pollMessages, interval);
            };

            const unlockFromConversation = (conversationPayload) => {
                if (!conversationPayload) return;
                cacheLead({
                    name: conversationPayload.name || '',
                    email: conversationPayload.email || '',
                    phone: conversationPayload.phone || '',
                });
                setConversationUnlocked(true);
                renderHistory(conversationPayload.messages || []);
                setUnread(0);
                syncSend();
                // Always poll: fast while the panel is open, slow in the background
                // when closed — otherwise a restored session never learns about
                // replies and the badge/sound can never fire.
                startPoll(open ? POLL_OPEN_MS : POLL_CLOSED_MS);
                if (open) {
                    input.focus();
                }
            };

            const setOpen = (next) => {
                open = next;
                panel.hidden = !open;
                root.classList.toggle('is-open', open);
                launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
                launcher.setAttribute('aria-label', open ? (i18n.minimize || 'Minimize') : (i18n.open || 'Open chat'));
                if (iconOpen) iconOpen.hidden = open;
                if (iconClose) iconClose.hidden = !open;
                if (launcherCopy) launcherCopy.hidden = open;

                if (open) {
                    if (lead) {
                        setConversationUnlocked(true);
                        input.focus();
                        scrollThread();
                        startPoll();
                        pollMessages();
                        setUnread(0);
                    } else {
                        setConversationUnlocked(false);
                        showLeadError('');
                        nameInput?.focus();
                        stopPoll();
                    }
                } else if (lead) {
                    // Keep a slow background poll alive so the badge + sound can
                    // announce replies that arrive while the panel is closed.
                    startPoll(POLL_CLOSED_MS);
                } else {
                    stopPoll();
                }
            };

            const hideChips = () => {
                if (chips) chips.hidden = true;
            };

            const syncSend = () => {
                const hasText = input.value.trim() !== '';
                const hasFile = Boolean(pendingFile);
                if (sendBtn) sendBtn.disabled = !lead || sending || (!hasText && !hasFile);
            };

            const sendMessage = async (text, file = null) => {
                if (!lead || sending) {
                    if (!lead) {
                        setConversationUnlocked(false);
                        nameInput?.focus();
                    }
                    return;
                }

                const value = String(text || '').trim();
                const attach = file || pendingFile;
                if (!value && !attach) return;

                sending = true;
                syncSend();
                hideChips();
                const valueToSend = value;
                const fileToSend = attach;
                const replyToId = replyTo?.id || null;
                input.value = '';
                clearPendingFile();
                clearReplyTo();
                syncSend();

                try {
                    const formData = new FormData();
                    formData.append('body', valueToSend);
                    if (fileToSend) {
                        formData.append('attachment', fileToSend, fileToSend.name);
                    }
                    if (replyToId) {
                        formData.append('reply_to_id', String(replyToId));
                    }

                    const payload = await api(endpoints.messages, {
                        method: 'POST',
                        body: formData,
                    });
                    if (payload.message) {
                        appendMessage(payload.message);
                    }
                    showComposeError('');
                } catch (error) {
                    input.value = valueToSend;
                    if (fileToSend) setPendingFile(fileToSend);
                    showComposeError(error?.status === 429
                        ? (i18n.rateLimited || error.message || i18n.networkError)
                        : (error.message || i18n.networkError));
                } finally {
                    sending = false;
                    syncSend();
                    input.focus();
                }
            };

            const sendText = async (text) => sendMessage(text, null);

            leadForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const result = validateLead();
                if (!result.ok) {
                    showLeadError(result.message);
                    return;
                }

                showLeadError('');
                if (leadSubmit) leadSubmit.disabled = true;

                try {
                    const payload = await api(endpoints.start, {
                        method: 'POST',
                        body: JSON.stringify({
                            ...result.lead,
                            greeting: i18n.greeting || null,
                            page_path: window.location.pathname,
                        }),
                    });
                    unlockFromConversation(payload.conversation);
                    input.focus();
                } catch (error) {
                    showLeadError(error?.status === 429
                        ? (i18n.rateLimited || error.message || i18n.networkError)
                        : (error.message || i18n.networkError));
                } finally {
                    if (leadSubmit) leadSubmit.disabled = false;
                }
            });

            launcher.addEventListener('click', () => setOpen(!open));
            closeBtn?.addEventListener('click', () => setOpen(false));

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                sendMessage(input.value, pendingFile);
            });

            const syncInputHeight = () => {
                input.style.height = 'auto';
                input.style.height = `${Math.min(input.scrollHeight, 112)}px`;
            };

            input.addEventListener('input', () => {
                syncSend();
                syncInputHeight();
                pingTyping();
            });
            syncInputHeight();
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    sendMessage(input.value, pendingFile);
                }
            });

            fileInput?.addEventListener('change', () => {
                const file = fileInput.files?.[0] || null;
                setPendingFile(file);
            });

            fileClear?.addEventListener('click', () => {
                clearPendingFile();
                showComposeError('');
            });

            replyClear?.addEventListener('click', () => {
                clearReplyTo();
                input.focus();
            });

            root.querySelectorAll('[data-sc-chat-chip]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    if (!open) setOpen(true);
                    if (!lead) {
                        setConversationUnlocked(false);
                        nameInput?.focus();
                        return;
                    }
                    sendText(btn.dataset.text || btn.textContent || '');
                });
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && open) setOpen(false);
            });

            document.addEventListener('click', (event) => {
                if (!open) return;
                if (root.contains(event.target)) return;
                setOpen(false);
            });

            // Prefill cached lead fields for UX only; source of truth is cookie + API.
            const cached = readCachedLead();
            if (cached) {
                if (nameInput) nameInput.value = cached.name;
                if (emailInput) emailInput.value = cached.email;
                if (phoneInput) phoneInput.value = cached.phone;
            }

            setConversationUnlocked(false);
            syncSend();

            api(endpoints.session)
                .then((payload) => {
                    if (payload?.conversation) {
                        unlockFromConversation(payload.conversation);
                    }
                })
                .catch(() => {
                    // stay on lead form
                });
        }
        initChatWidget();
    });
})();
