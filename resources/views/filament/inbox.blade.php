<x-filament-panels::page full-height>
    @php
        $conversations = $this->conversations;
        $record = $this->activeConversation;
        $messages = $record ? $this->thread : collect();
        $visitorTyping = $this->visitorTyping;
        $replyTarget = $record ? $this->replyTargetPreview() : null;
        $attachments = app(\Miran\SupportChat\Support\AttachmentService::class);
        $accept = collect($attachments->allowedExtensions())
            ->map(static fn (string $ext): string => '.'.$ext)
            ->implode(',');
        $isOpen = $record?->status === 'open';
        $hasAttachment = $this->composerAttachment !== null;
        $attachmentName = $hasAttachment ? $this->composerAttachment->getClientOriginalName() : null;
        $filters = [
            'open' => __('support-chat::admin.chat.status_open'),
            'closed' => __('support-chat::admin.chat.status_closed'),
            'all' => __('support-chat::admin.chat.filter_all'),
        ];
    @endphp

    <div
        class="sc-desk{{ $record ? ' is-open' : '' }}"
        wire:poll.5s="poll"
        data-sc-chat-thread-shell
    >
        <aside class="sc-rail" aria-label="{{ __('support-chat::admin.models.conversations') }}">
            <div class="sc-rail__head">
                <p class="sc-rail__kicker">{{ __('support-chat::admin.nav.group') }}</p>
                <h2 class="sc-rail__title">{{ __('support-chat::admin.nav.chat') }}</h2>
            </div>

            <div class="sc-rail__search">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="sc-rail__search-icon" />
                <label class="sr-only" for="sc-desk-search">{{ __('support-chat::admin.chat.search_placeholder') }}</label>
                <input
                    id="sc-desk-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('support-chat::admin.chat.search_placeholder') }}"
                    autocomplete="off"
                >
            </div>

            <div class="sc-rail__filters" role="tablist">
                @foreach ($filters as $value => $label)
                    <button
                        type="button"
                        role="tab"
                        wire:click="$set('statusFilter', '{{ $value }}')"
                        @class(['sc-rail__filter', 'is-on' => $this->statusFilter === $value])
                        aria-selected="{{ $this->statusFilter === $value ? 'true' : 'false' }}"
                    >{{ $label }}</button>
                @endforeach
            </div>

            <div class="sc-rail__list" role="list">
                @forelse ($conversations as $conversation)
                    @php
                        $isActive = $record?->id === $conversation->id;
                        $unread = (int) ($conversation->unread_count ?? 0);
                        $preview = $conversation->lastPreview();
                        $latest = $conversation->latestMessage;
                        if ($latest?->sender === 'agent' && $preview !== '') {
                            $preview = __('support-chat::admin.chat.you').': '.$preview;
                        }
                    @endphp
                    <button
                        type="button"
                        role="listitem"
                        wire:click="selectConversation({{ $conversation->id }})"
                        wire:key="sc-row-{{ $conversation->id }}-{{ $conversation->last_message_at?->timestamp }}-{{ $unread }}"
                        @class(['sc-row', 'is-active' => $isActive, 'has-unread' => $unread > 0])
                    >
                        <span class="sc-row__avatar" aria-hidden="true">{{ $conversation->initials() }}</span>
                        <span class="sc-row__body">
                            <span class="sc-row__top">
                                <span class="sc-row__name">{{ $conversation->name }}</span>
                                <span class="sc-row__time">{{ $this->listTimestamp($conversation->last_message_at) }}</span>
                            </span>
                            <span class="sc-row__bottom">
                                <span class="sc-row__preview">{{ $preview !== '' ? $preview : '—' }}</span>
                                @if ($unread > 0)
                                    <span class="sc-row__badge">{{ $unread > 9 ? '9+' : $unread }}</span>
                                @endif
                            </span>
                        </span>
                    </button>
                @empty
                    <p class="sc-rail__empty">{{ __('support-chat::admin.chat.empty_list') }}</p>
                @endforelse

                @if ($conversations->count() === 80)
                    <p class="sc-rail__cap">{{ __('support-chat::admin.chat.list_capped') }}</p>
                @endif
            </div>
        </aside>

        <section class="sc-stage" aria-live="polite">
            @if (! $record)
                <div class="sc-empty">
                    <span class="sc-empty__spark" aria-hidden="true"></span>
                    <h3>{{ __('support-chat::admin.chat.empty_desk') }}</h3>
                    <p>{{ __('support-chat::admin.chat.empty_desk_hint') }}</p>
                </div>
            @else
                <header class="sc-stage__head">
                    <button
                        type="button"
                        class="sc-stage__back"
                        wire:click="clearSelection"
                    >
                        <x-filament::icon icon="heroicon-o-chevron-left" class="sc-stage__back-icon h-5 w-5" />
                        <span>{{ __('support-chat::admin.chat.back') }}</span>
                    </button>

                    <span class="sc-row__avatar sc-stage__avatar" aria-hidden="true">{{ $record->initials() }}</span>

                    <div class="sc-stage__who">
                        <h3>{{ $record->name }}</h3>
                        <p>
                            {{ $record->email }}
                            @if (filled($record->phone_display))
                                <span class="sc-stage__dot">·</span>
                                {{ $record->phone_display }}
                            @endif
                        </p>
                    </div>

                    <span @class(['sc-status', 'is-closed' => $record->status !== 'open'])>
                        {{ $record->status === 'open' ? __('support-chat::admin.chat.status_open') : __('support-chat::admin.chat.status_closed') }}
                    </span>

                    <div class="sc-stage__actions">
                        @if ($isOpen)
                            <button type="button" class="sc-icon-btn" wire:click="closeConversation" wire:confirm="{{ __('support-chat::admin.chat.close') }}?" title="{{ __('support-chat::admin.chat.close') }}">
                                <x-filament::icon icon="heroicon-o-lock-closed" class="h-4 w-4" />
                            </button>
                        @else
                            <button type="button" class="sc-icon-btn" wire:click="reopenConversation" title="{{ __('support-chat::admin.chat.reopen') }}">
                                <x-filament::icon icon="heroicon-o-lock-open" class="h-4 w-4" />
                            </button>
                        @endif
                        <button
                            type="button"
                            class="sc-icon-btn is-danger"
                            wire:click="deleteConversation"
                            wire:confirm="{{ __('support-chat::admin.chat.delete_confirm') }}"
                            title="{{ __('support-chat::admin.chat.delete') }}"
                        >
                            <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                        </button>
                    </div>
                </header>

                <div class="sc-stage__messages" data-sc-chat-messages wire:key="sc-thread-{{ $record->id }}">
                    @forelse ($messages as $message)
                        @php
                            $isVisitor = $message->sender === 'visitor';
                            $isAgent = $message->sender === 'agent';
                            $parent = $message->replyTo;
                        @endphp
                        <div
                            wire:key="sc-msg-{{ $message->id }}"
                            @class(['sc-bubble-row', 'is-agent' => $isAgent, 'is-visitor' => $isVisitor, 'is-system' => ! $isVisitor && ! $isAgent])
                        >
                            <div class="sc-bubble">
                                <div class="sc-bubble__meta">
                                    <span>{{ __('support-chat::admin.chat.sender_'.$message->sender) }}</span>
                                    @if ($message->sender !== 'system' && $isOpen)
                                        <button type="button" wire:click="setReplyTarget({{ $message->id }})">
                                            {{ __('support-chat::admin.chat.reply_to_message') }}
                                        </button>
                                    @endif
                                </div>

                                @if ($parent)
                                    <div class="sc-quote">
                                        <p class="sc-quote__who">{{ __('support-chat::admin.chat.sender_'.$parent->sender) }}</p>
                                        <p>
                                            @if (filled($parent->body))
                                                {{ \Illuminate\Support\Str::limit($parent->body, 100) }}
                                            @elseif ($parent->hasAttachment())
                                                {{ $parent->attachment_name }}
                                            @else
                                                —
                                            @endif
                                        </p>
                                    </div>
                                @endif

                                @if (filled($message->body))
                                    <p class="sc-bubble__text">{{ $message->body }}</p>
                                @endif

                                @if ($message->hasAttachment())
                                    @php
                                        $isImage = $message->attachmentIsImage();
                                        $sizeKb = $message->attachment_size
                                            ? number_format(((int) $message->attachment_size) / 1024, 1).' KB'
                                            : null;
                                    @endphp
                                    <div class="sc-file">
                                        @if ($isImage)
                                            <a
                                                href="{{ route('support-chat.filament.attachment.preview', ['message' => $message->id]) }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <img
                                                    src="{{ route('support-chat.filament.attachment.preview', ['message' => $message->id]) }}"
                                                    alt="{{ $message->attachment_name }}"
                                                    loading="lazy"
                                                >
                                            </a>
                                        @endif
                                        <div class="sc-file__row">
                                            @unless ($isImage)
                                                <span class="sc-file__icon">📄</span>
                                            @endunless
                                            <div class="sc-file__meta">
                                                <p>{{ $message->attachment_name }}</p>
                                                @if ($sizeKb)
                                                    <span>{{ $sizeKb }}</span>
                                                @endif
                                            </div>
                                            <button type="button" wire:click="downloadAttachment({{ $message->id }})">
                                                {{ __('support-chat::admin.chat.download_attachment') }}
                                            </button>
                                        </div>
                                    </div>
                                @endif

                                <time datetime="{{ $message->created_at?->toIso8601String() }}">
                                    {{ $message->created_at?->format('H:i') }}
                                </time>
                            </div>
                        </div>
                    @empty
                        <p class="sc-stage__empty">{{ __('support-chat::admin.chat.empty_thread') }}</p>
                    @endforelse

                    @if ($visitorTyping)
                        <div class="sc-bubble-row is-visitor">
                            <div class="sc-typing">
                                <span></span><span></span><span></span>
                                {{ __('support-chat::admin.chat.visitor_typing') }}
                            </div>
                        </div>
                    @endif
                </div>

                <div class="sc-composer">
                    @if ($replyTarget)
                        <div class="sc-chip">
                            <div>
                                <p class="sc-chip__label">
                                    {{ __('support-chat::admin.chat.replying_to') }}
                                    {{ __('support-chat::admin.chat.sender_'.$replyTarget->sender) }}
                                </p>
                                <p class="sc-chip__text">
                                    @if (filled($replyTarget->body))
                                        {{ \Illuminate\Support\Str::limit($replyTarget->body, 120) }}
                                    @elseif ($replyTarget->hasAttachment())
                                        {{ $replyTarget->attachment_name }}
                                    @else
                                        —
                                    @endif
                                </p>
                            </div>
                            <button type="button" wire:click="clearReplyTarget">{{ __('support-chat::admin.chat.reply_cancel') }}</button>
                        </div>
                    @endif

                    @if ($isOpen)
                        <form wire:submit="sendComposer">
                            @if ($hasAttachment)
                                <div class="sc-chip">
                                    <span class="sc-chip__text">{{ $attachmentName }}</span>
                                    <button type="button" wire:click="clearComposerAttachment">
                                        {{ __('support-chat::admin.chat.composer_remove_file') }}
                                    </button>
                                </div>
                            @endif

                            <div class="sc-composer__box">
                                <label class="sr-only" for="sc-chat-composer-body">{{ __('support-chat::admin.chat.composer_placeholder') }}</label>
                                <textarea
                                    id="sc-chat-composer-body"
                                    wire:model="composerBody"
                                    rows="2"
                                    maxlength="{{ app(\Miran\SupportChat\Support\ChatService::class)->maxMessageLength() }}"
                                    placeholder="{{ __('support-chat::admin.chat.composer_placeholder') }}"
                                    x-data
                                    x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.sendComposer() }"
                                ></textarea>
                                <div class="sc-composer__bar">
                                    <label class="sc-composer__attach" title="{{ __('support-chat::admin.chat.composer_attach') }}">
                                        <span class="sr-only">{{ __('support-chat::admin.chat.composer_attach') }}</span>
                                        <x-filament::icon icon="heroicon-o-paper-clip" class="h-5 w-5" />
                                        <input type="file" class="sr-only" wire:model="composerAttachment" accept="{{ $accept }}">
                                    </label>
                                    <span class="sc-composer__hint">
                                        {{ __('support-chat::admin.chat.composer_attach_hint', ['max' => max(1, (int) round($attachments->maxKilobytes() / 1024))]) }}
                                    </span>
                                    <button
                                        type="submit"
                                        class="sc-composer__send"
                                        wire:loading.attr="disabled"
                                        wire:target="sendComposer,composerAttachment"
                                        title="{{ __('support-chat::admin.chat.composer_send') }}"
                                    >
                                        <span class="sr-only">{{ __('support-chat::admin.chat.composer_send') }}</span>
                                        <x-filament::icon icon="heroicon-s-paper-airplane" class="h-4 w-4" wire:loading.remove wire:target="sendComposer" />
                                        <x-filament::loading-indicator class="h-4 w-4" wire:loading wire:target="sendComposer" />
                                    </button>
                                </div>
                            </div>

                            @error('composerAttachment')
                                <p class="sc-composer__error">{{ $message }}</p>
                            @enderror
                            @error('composerBody')
                                <p class="sc-composer__error">{{ $message }}</p>
                            @enderror

                            <div wire:loading wire:target="composerAttachment" class="sc-composer__hint">
                                {{ __('support-chat::admin.chat.composer_uploading') }}
                            </div>
                        </form>
                    @else
                        <p class="sc-composer__closed">{{ __('support-chat::admin.chat.composer_closed') }}</p>
                    @endif
                </div>
            @endif
        </section>
    </div>

    <style>
        .fi-page.sc-desk-page.fi-height-full,
        .fi-page.sc-desk-page {
            height: calc(100dvh - 4.25rem);
            max-height: calc(100dvh - 4.25rem);
        }
        .fi-page.sc-desk-page .fi-page-header-main-ctn,
        .fi-page.sc-desk-page .fi-page-main,
        .fi-page.sc-desk-page .fi-page-content {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            height: 100%;
            max-width: none;
            padding: 0;
        }

        .sc-desk {
            --sc-navy: #1a3f73;
            --sc-navy-bright: #3d6fb5;
            --sc-navy-ink: #08162c;
            --sc-coral: #f0627a;
            --sc-paper: #ffffff;
            --sc-mist: #f2f5f9;
            --sc-line: #d0dbe8;
            --sc-ink: #122033;
            --sc-ink-soft: #5a6b7d;
            --sc-blotter: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='48' height='48' viewBox='0 0 48 48'%3E%3Ccircle cx='6' cy='10' r='1.25' fill='%231a3f73' fill-opacity='0.09'/%3E%3Ccircle cx='28' cy='22' r='1' fill='%233d6fb5' fill-opacity='0.08'/%3E%3Ccircle cx='40' cy='8' r='1.1' fill='%23f0627a' fill-opacity='0.07'/%3E%3Ccircle cx='16' cy='36' r='1' fill='%231a3f73' fill-opacity='0.07'/%3E%3Ccircle cx='38' cy='38' r='1.2' fill='%233d6fb5' fill-opacity='0.08'/%3E%3C/svg%3E");
            display: flex;
            flex-direction: row;
            flex: 1;
            min-height: 0;
            height: 100%;
            overflow: hidden;
            background: var(--sc-mist);
            color: var(--sc-ink);
            border-block-start: 1px solid var(--sc-line);
        }
        .sc-desk.is-open {
            /* used by mobile CSS */
        }

        .sc-rail {
            display: flex;
            flex-direction: column;
            width: min(22.5rem, 38vw);
            min-width: 17rem;
            background: var(--sc-paper);
            border-inline-end: 1px solid var(--sc-line);
        }
        .sc-rail__head {
            padding: 1rem 1.1rem 0.35rem;
        }
        .sc-rail__kicker {
            margin: 0;
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--sc-ink-soft);
        }
        .sc-rail__title {
            margin: 0.15rem 0 0;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--sc-navy-ink);
        }
        .sc-rail__search {
            position: relative;
            margin: 0.65rem 0.9rem 0.4rem;
        }
        .sc-rail__search-icon {
            position: absolute;
            inset-inline-start: 0.75rem;
            top: 50%;
            width: 1rem;
            height: 1rem;
            transform: translateY(-50%);
            color: var(--sc-ink-soft);
        }
        .sc-rail__search input {
            width: 100%;
            border: 1px solid var(--sc-line);
            border-radius: 999px;
            background: var(--sc-mist);
            padding: 0.55rem 0.85rem 0.55rem 2.15rem;
            font-size: 0.85rem;
            color: var(--sc-ink);
            outline: none;
        }
        [dir="rtl"] .sc-rail__search input {
            padding: 0.55rem 2.15rem 0.55rem 0.85rem;
        }
        .sc-rail__search input:focus {
            border-color: color-mix(in srgb, var(--sc-navy-bright) 70%, var(--sc-line));
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--sc-navy-bright) 16%, transparent);
            background: var(--sc-paper);
        }
        .sc-rail__filters {
            display: flex;
            gap: 0.35rem;
            padding: 0.35rem 0.9rem 0.7rem;
        }
        .sc-rail__filter {
            border: 1px solid transparent;
            border-radius: 999px;
            background: transparent;
            padding: 0.28rem 0.7rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--sc-ink-soft);
            cursor: pointer;
        }
        .sc-rail__filter.is-on {
            background: color-mix(in srgb, var(--sc-navy) 10%, white);
            border-color: color-mix(in srgb, var(--sc-navy-bright) 35%, var(--sc-line));
            color: var(--sc-navy);
        }
        .sc-rail__list {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }
        .sc-rail__empty,
        .sc-rail__cap,
        .sc-stage__empty {
            margin: 0;
            padding: 1.25rem 1rem;
            font-size: 0.85rem;
            color: var(--sc-ink-soft);
            text-align: center;
        }
        .sc-rail__cap {
            padding-top: 0;
            font-size: 0.72rem;
        }

        .sc-row {
            display: flex;
            width: 100%;
            gap: 0.75rem;
            padding: 0.7rem 1rem 0.7rem 0.85rem;
            border: 0;
            border-inline-start: 3px solid transparent;
            background: transparent;
            text-align: start;
            cursor: pointer;
            color: inherit;
        }
        .sc-row:hover {
            background: var(--sc-mist);
        }
        .sc-row.is-active {
            background: color-mix(in srgb, var(--sc-navy) 7%, white);
            border-inline-start-color: var(--sc-coral);
        }
        .sc-row.has-unread .sc-row__name {
            font-weight: 800;
        }
        .sc-row__avatar,
        .sc-stage__avatar {
            display: inline-flex;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 999px;
            background: linear-gradient(145deg, var(--sc-navy), #122c52);
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .sc-row__body {
            min-width: 0;
            flex: 1;
        }
        .sc-row__top,
        .sc-row__bottom {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .sc-row__name {
            overflow: hidden;
            font-size: 0.9rem;
            font-weight: 650;
            letter-spacing: -0.01em;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--sc-navy-ink);
        }
        .sc-row__time {
            flex-shrink: 0;
            font-size: 0.65rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--sc-ink-soft);
        }
        .sc-row.has-unread .sc-row__time {
            color: var(--sc-coral);
        }
        .sc-row__preview {
            overflow: hidden;
            margin-top: 0.15rem;
            font-size: 0.78rem;
            line-height: 1.35;
            color: var(--sc-ink-soft);
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .sc-row__badge {
            display: inline-flex;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            min-width: 1.15rem;
            height: 1.15rem;
            border-radius: 999px;
            background: var(--sc-coral);
            padding: 0 0.3rem;
            font-size: 0.62rem;
            font-weight: 800;
            color: #fff;
        }

        .sc-stage {
            display: flex;
            flex: 1;
            min-width: 0;
            flex-direction: column;
            background:
                var(--sc-blotter),
                linear-gradient(180deg, color-mix(in srgb, var(--sc-mist) 70%, white), var(--sc-mist));
        }
        .sc-empty {
            display: flex;
            flex: 1;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 2rem;
            text-align: center;
        }
        .sc-empty__spark {
            width: 0.65rem;
            height: 0.65rem;
            margin-bottom: 0.55rem;
            border-radius: 999px;
            background: var(--sc-coral);
            box-shadow: 0 0 0 6px color-mix(in srgb, var(--sc-coral) 18%, transparent);
        }
        .sc-empty h3 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--sc-navy-ink);
        }
        .sc-empty p {
            margin: 0;
            max-width: 22rem;
            font-size: 0.9rem;
            color: var(--sc-ink-soft);
        }

        .sc-stage__head {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-height: 3.75rem;
            padding: 0.65rem 1rem;
            background: color-mix(in srgb, var(--sc-paper) 88%, transparent);
            border-block-end: 1px solid var(--sc-line);
            backdrop-filter: blur(8px);
        }
        .sc-stage__back {
            display: none;
            align-items: center;
            gap: 0.15rem;
            border: 0;
            background: transparent;
            color: var(--sc-navy);
            font-size: 0.8rem;
            font-weight: 650;
            cursor: pointer;
        }
        [dir="rtl"] .sc-stage__back-icon {
            transform: rotate(180deg);
        }
        .sc-stage__who {
            min-width: 0;
            flex: 1;
        }
        .sc-stage__who h3 {
            margin: 0;
            overflow: hidden;
            font-size: 0.95rem;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--sc-navy-ink);
        }
        .sc-stage__who p {
            margin: 0.1rem 0 0;
            overflow: hidden;
            font-size: 0.72rem;
            color: var(--sc-ink-soft);
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .sc-stage__dot {
            margin: 0 0.2rem;
            opacity: 0.5;
        }
        .sc-status {
            flex-shrink: 0;
            border-radius: 999px;
            background: color-mix(in srgb, var(--sc-navy) 12%, white);
            padding: 0.2rem 0.55rem;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--sc-navy);
        }
        .sc-status.is-closed {
            background: color-mix(in srgb, var(--sc-ink-soft) 16%, white);
            color: var(--sc-ink-soft);
        }
        .sc-stage__actions {
            display: flex;
            gap: 0.25rem;
        }
        .sc-icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border: 1px solid var(--sc-line);
            border-radius: 0.55rem;
            background: var(--sc-paper);
            color: var(--sc-ink-soft);
            cursor: pointer;
        }
        .sc-icon-btn:hover {
            color: var(--sc-navy-ink);
            border-color: var(--sc-navy-bright);
        }
        .sc-icon-btn.is-danger:hover {
            color: var(--sc-coral);
            border-color: var(--sc-coral);
        }

        .sc-stage__messages {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            padding: 1rem 1.1rem 1.25rem;
        }
        .sc-bubble-row {
            display: flex;
            margin-bottom: 0.55rem;
        }
        .sc-bubble-row.is-agent {
            justify-content: flex-end;
            padding-inline-start: 4.5rem;
        }
        .sc-bubble-row.is-visitor,
        .sc-bubble-row.is-system {
            justify-content: flex-start;
            padding-inline-end: 4.5rem;
        }
        .sc-bubble {
            max-width: min(36rem, 100%);
            border-radius: 1.05rem;
            padding: 0.55rem 0.8rem 0.45rem;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        .sc-bubble-row.is-agent .sc-bubble {
            background: linear-gradient(145deg, var(--sc-navy), #122c52);
            color: #fff;
            border-end-end-radius: 0.3rem;
        }
        .sc-bubble-row.is-visitor .sc-bubble {
            background: var(--sc-paper);
            border: 1px solid var(--sc-line);
            color: var(--sc-ink);
            border-end-start-radius: 0.3rem;
        }
        .sc-bubble-row.is-system .sc-bubble {
            background: transparent;
            border: 1px dashed var(--sc-line);
            color: var(--sc-ink-soft);
            font-size: 0.8rem;
        }
        .sc-bubble__meta {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.2rem;
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.7;
        }
        .sc-bubble__meta button {
            border: 0;
            background: transparent;
            color: inherit;
            cursor: pointer;
            font: inherit;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.85;
        }
        .sc-bubble__meta button:hover {
            text-decoration: underline;
            opacity: 1;
        }
        .sc-bubble__text {
            margin: 0;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }
        .sc-bubble time {
            display: block;
            margin-top: 0.25rem;
            font-size: 0.62rem;
            opacity: 0.6;
        }
        .sc-quote {
            margin: 0 0 0.4rem;
            border-inline-start: 3px solid color-mix(in srgb, var(--sc-coral) 70%, white);
            padding: 0.3rem 0.55rem;
            border-radius: 0.4rem;
            background: rgb(255 255 255 / 0.12);
            font-size: 0.75rem;
        }
        .sc-bubble-row.is-visitor .sc-quote {
            background: var(--sc-mist);
        }
        .sc-quote__who {
            margin: 0 0 0.1rem;
            font-weight: 700;
        }
        .sc-quote p {
            margin: 0;
        }
        .sc-file {
            margin-top: 0.4rem;
            overflow: hidden;
            border-radius: 0.7rem;
            border: 1px solid rgb(255 255 255 / 0.2);
        }
        .sc-bubble-row.is-visitor .sc-file {
            border-color: var(--sc-line);
        }
        .sc-file img {
            display: block;
            width: 11rem;
            max-width: 100%;
            height: 5rem;
            object-fit: cover;
        }
        .sc-file__row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.55rem;
        }
        .sc-file__meta {
            min-width: 0;
            flex: 1;
        }
        .sc-file__meta p {
            margin: 0;
            overflow: hidden;
            font-size: 0.72rem;
            font-weight: 650;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .sc-file__meta span {
            font-size: 0.62rem;
            opacity: 0.7;
        }
        .sc-file__row button {
            border: 0;
            background: transparent;
            color: inherit;
            cursor: pointer;
            font-size: 0.68rem;
            font-weight: 650;
            text-decoration: underline;
        }

        .sc-typing {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border: 1px dashed var(--sc-line);
            border-radius: 999px;
            background: var(--sc-paper);
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
            color: var(--sc-ink-soft);
        }
        .sc-typing span {
            width: 0.35rem;
            height: 0.35rem;
            border-radius: 999px;
            background: var(--sc-navy-bright);
            animation: sc-desk-blink 1.2s infinite;
        }
        .sc-typing span:nth-child(2) { animation-delay: 0.15s; }
        .sc-typing span:nth-child(3) { animation-delay: 0.3s; }
        @keyframes sc-desk-blink {
            0%, 80%, 100% { opacity: 0.25; }
            40% { opacity: 1; }
        }

        .sc-composer {
            padding: 0.7rem 0.9rem 0.9rem;
            background: color-mix(in srgb, var(--sc-paper) 92%, transparent);
            border-block-start: 1px solid var(--sc-line);
        }
        .sc-chip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
            border: 1px solid var(--sc-line);
            border-inline-start: 3px solid var(--sc-coral);
            border-radius: 0.7rem;
            background: var(--sc-paper);
            padding: 0.5rem 0.7rem;
        }
        .sc-chip__label {
            margin: 0;
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--sc-ink-soft);
        }
        .sc-chip__text {
            margin: 0.1rem 0 0;
            overflow: hidden;
            font-size: 0.82rem;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .sc-chip button {
            flex-shrink: 0;
            border: 0;
            background: transparent;
            color: var(--sc-ink-soft);
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 650;
        }
        .sc-composer__box {
            border: 1px solid var(--sc-line);
            border-radius: 1rem;
            background: var(--sc-paper);
        }
        .sc-composer__box textarea {
            display: block;
            width: 100%;
            min-height: 3.1rem;
            max-height: 8.5rem;
            resize: none;
            border: 0;
            background: transparent;
            padding: 0.75rem 0.9rem 0.25rem;
            font: inherit;
            font-size: 0.9rem;
            line-height: 1.45;
            color: var(--sc-ink);
            outline: none;
            field-sizing: content;
        }
        .sc-composer__box textarea::placeholder {
            color: color-mix(in srgb, var(--sc-ink-soft) 80%, transparent);
        }
        .sc-composer__bar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.55rem 0.55rem;
        }
        .sc-composer__attach {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.1rem;
            height: 2.1rem;
            border-radius: 999px;
            color: var(--sc-ink-soft);
            cursor: pointer;
        }
        .sc-composer__attach:hover {
            background: var(--sc-mist);
            color: var(--sc-navy);
        }
        .sc-composer__hint {
            flex: 1;
            font-size: 0.65rem;
            color: var(--sc-ink-soft);
        }
        .sc-composer__send {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.15rem;
            height: 2.15rem;
            border: 0;
            border-radius: 999px;
            background: var(--sc-coral);
            color: #fff;
            cursor: pointer;
        }
        .sc-composer__send:hover {
            background: color-mix(in srgb, var(--sc-coral) 80%, #8a2034);
        }
        .sc-composer__send:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .sc-composer__error {
            margin: 0.4rem 0 0;
            font-size: 0.75rem;
            color: #b42318;
        }
        .sc-composer__closed {
            margin: 0;
            border: 1px dashed var(--sc-line);
            border-radius: 0.85rem;
            background: var(--sc-mist);
            padding: 0.75rem 0.9rem;
            font-size: 0.85rem;
            color: var(--sc-ink-soft);
        }

        [data-sc-chat-thread-shell].sc-chat-thread--flash .sc-stage {
            box-shadow: inset 0 0 0 2px var(--sc-coral);
        }

        .dark .sc-desk {
            --sc-paper: #122033;
            --sc-mist: #0c1a2e;
            --sc-line: #24364c;
            --sc-ink: #e8eef6;
            --sc-ink-soft: #8ea0b5;
            --sc-navy-ink: #f3f6fb;
            background: var(--sc-mist);
        }
        .dark .sc-rail,
        .dark .sc-composer__box,
        .dark .sc-chip,
        .dark .sc-icon-btn,
        .dark .sc-typing {
            background: #0f1c2e;
        }
        .dark .sc-row:hover {
            background: #15263c;
        }
        .dark .sc-row.is-active {
            background: color-mix(in srgb, var(--sc-navy-bright) 16%, #0f1c2e);
        }
        .dark .sc-rail__search input,
        .dark .sc-composer__closed {
            background: #0c1a2e;
            color: var(--sc-ink);
        }
        .dark .sc-rail__filter.is-on {
            background: color-mix(in srgb, var(--sc-navy-bright) 18%, #0f1c2e);
            color: #d5e4f7;
        }
        .dark .sc-bubble-row.is-visitor .sc-bubble {
            background: #15263c;
            border-color: #24364c;
            color: var(--sc-ink);
        }
        .dark .sc-stage__head,
        .dark .sc-composer {
            background: rgb(12 26 46 / 0.92);
        }
        .dark .sc-status {
            background: color-mix(in srgb, var(--sc-navy-bright) 22%, #0f1c2e);
            color: #d5e4f7;
        }
        .dark .sc-composer__send {
            background: var(--sc-coral);
            color: #fff;
        }

        @media (max-width: 767px) {
            .fi-page.sc-desk-page {
                height: calc(100dvh - 3.75rem);
            }
            .sc-rail {
                width: 100%;
                min-width: 0;
                border-inline-end: 0;
            }
            .sc-desk:not(.is-open) .sc-stage {
                display: none;
            }
            .sc-desk.is-open .sc-rail {
                display: none;
            }
            .sc-stage__back {
                display: inline-flex;
            }
            .sc-bubble-row.is-agent,
            .sc-bubble-row.is-visitor,
            .sc-bubble-row.is-system {
                padding-inline: 0;
            }
            .sc-composer__hint {
                display: none;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .sc-typing span {
                animation: none;
            }
        }
    </style>

    @script
    <script>
        (() => {
            let audioCtx = null;

            const chime = () => {
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
                        gain.gain.exponentialRampToValueAtTime(0.12, t0 + delay + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.0001, t0 + delay + 0.28);
                        osc.connect(gain).connect(audioCtx.destination);
                        osc.start(t0 + delay);
                        osc.stop(t0 + delay + 0.3);
                    });
                } catch {
                    // audio unavailable
                }
            };

            const flash = () => {
                const shell = document.querySelector('[data-sc-chat-thread-shell]');
                if (!shell) return;
                shell.classList.add('sc-chat-thread--flash');
                window.setTimeout(() => shell.classList.remove('sc-chat-thread--flash'), 1400);
            };

            const scrollThread = () => {
                const scroller = document.querySelector('[data-sc-chat-messages]');
                if (!scroller) return;
                scroller.scrollTop = scroller.scrollHeight;
            };

            $wire.on('sc-chat-visitor-replied', () => {
                chime();
                flash();
                queueMicrotask(scrollThread);
            });
            $wire.on('sc-chat-composer-sent', () => queueMicrotask(scrollThread));
            $wire.on('sc-chat-thread-opened', () => queueMicrotask(scrollThread));
            queueMicrotask(scrollThread);
        })();
    </script>
    @endscript
</x-filament-panels::page>
