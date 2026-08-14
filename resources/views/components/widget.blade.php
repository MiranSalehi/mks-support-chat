@php
    $i18n = [
        'greeting' => __('support-chat::widget.greeting'),
        'agent' => __('support-chat::widget.agent'),
        'you' => __('support-chat::widget.you'),
        'placeholder' => __('support-chat::widget.placeholder'),
        'typing' => __('support-chat::widget.typing'),
        'open' => __('support-chat::widget.open'),
        'minimize' => __('support-chat::widget.minimize'),
        'leadRequired' => __('support-chat::widget.lead_required'),
        'leadInvalidEmail' => __('support-chat::widget.lead_invalid_email'),
        'leadInvalidPhone' => __('support-chat::widget.lead_invalid_phone'),
        'networkError' => __('support-chat::widget.network_error'),
        'rateLimited' => __('support-chat::widget.rate_limited'),
        'newMessages' => __('support-chat::widget.new_messages'),
        'attachTooBig' => __('support-chat::widget.attach_too_big'),
        'attachBadType' => __('support-chat::widget.attach_bad_type'),
        'download' => __('support-chat::widget.download'),
        'reply' => __('support-chat::widget.reply'),
        'replyingTo' => __('support-chat::widget.replying_to'),
        'replyCancel' => __('support-chat::widget.reply_cancel'),
        'chips' => array_values(array_filter(
            config('support-chat.widget.quick_replies', []),
            static fn ($item): bool => is_string($item) && trim($item) !== ''
        )),
    ];
    $endpoints = [
        'session' => route('support-chat.session'),
        'start' => route('support-chat.start'),
        'messages' => route('support-chat.messages'),
        'typing' => route('support-chat.typing'),
        'read' => route('support-chat.read'),
    ];
    $avatarMark = (string) config('support-chat.widget.avatar_mark', 'SC');
@endphp

<link rel="stylesheet" href="{{ route('support-chat.assets.css') }}">

<div
    class="sc-chat"
    data-sc-chat
    data-i18n='@json($i18n)'
    data-endpoints='@json($endpoints)'
>
    <div
        class="sc-chat__panel"
        data-sc-chat-panel
        hidden
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('support-chat::widget.title') }}"
    >
        <header class="sc-chat__head">
            <div class="sc-chat__identity">
                <span class="sc-chat__avatar" aria-hidden="true">
                    <span class="sc-chat__avatar-mark">{{ $avatarMark }}</span>
                </span>
                <div class="sc-chat__meta">
                    <p class="sc-chat__title">{{ __('support-chat::widget.title') }}</p>
                    <p class="sc-chat__status">
                        <span class="sc-chat__pulse" aria-hidden="true"></span>
                        {{ __('support-chat::widget.status') }}
                    </p>
                </div>
            </div>
            <button
                type="button"
                class="sc-chat__icon-btn"
                data-sc-chat-close
                aria-label="{{ __('support-chat::widget.minimize') }}"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M6 12h12" stroke-linecap="round"/>
                </svg>
            </button>
        </header>

        <div class="sc-chat__lead" data-sc-chat-lead>
            <div class="sc-chat__lead-intro">
                <p class="sc-chat__lead-eyebrow">{{ __('support-chat::widget.lead_eyebrow') }}</p>
                <h2 class="sc-chat__lead-title">{{ __('support-chat::widget.lead_title') }}</h2>
                <p class="sc-chat__lead-text">{{ __('support-chat::widget.lead_text') }}</p>
            </div>

            <form class="sc-chat__lead-form" data-sc-chat-lead-form novalidate>
                <p class="sc-chat__lead-error" data-sc-chat-lead-error hidden></p>

                <label class="sc-chat__field">
                    <span class="sc-chat__field-label">{{ __('support-chat::widget.lead_name') }}</span>
                    <input
                        type="text"
                        name="name"
                        class="sc-chat__field-input"
                        data-sc-chat-lead-name
                        autocomplete="name"
                        required
                        maxlength="120"
                        placeholder="{{ __('support-chat::widget.lead_name_ph') }}"
                    >
                </label>

                <label class="sc-chat__field">
                    <span class="sc-chat__field-label">{{ __('support-chat::widget.lead_email') }}</span>
                    <input
                        type="email"
                        name="email"
                        class="sc-chat__field-input"
                        data-sc-chat-lead-email
                        autocomplete="email"
                        inputmode="email"
                        required
                        maxlength="190"
                        placeholder="{{ __('support-chat::widget.lead_email_ph') }}"
                    >
                </label>

                <label class="sc-chat__field">
                    <span class="sc-chat__field-label">{{ __('support-chat::widget.lead_phone') }}</span>
                    <input
                        type="tel"
                        name="phone"
                        class="sc-chat__field-input"
                        data-sc-chat-lead-phone
                        autocomplete="tel"
                        inputmode="tel"
                        required
                        maxlength="32"
                        placeholder="{{ __('support-chat::widget.lead_phone_ph') }}"
                    >
                </label>

                <button type="submit" class="sc-chat__lead-submit" data-sc-chat-lead-submit>
                    {{ __('support-chat::widget.lead_submit') }}
                </button>
            </form>
        </div>

        <div class="sc-chat__conversation" data-sc-chat-conversation hidden>
            <div class="sc-chat__stage" aria-live="polite">
                <div class="sc-chat__thread" data-sc-chat-thread></div>

                <div class="sc-chat__chips" data-sc-chat-chips @if ($i18n['chips'] === []) hidden @endif>
                    @foreach ($i18n['chips'] as $chip)
                        <button type="button" class="sc-chat__chip" data-sc-chat-chip data-text="{{ $chip }}">{{ $chip }}</button>
                    @endforeach
                </div>
            </div>

            <p class="sc-chat__compose-error" data-sc-chat-compose-error hidden></p>

            <div class="sc-chat__reply-bar" data-sc-chat-reply-bar hidden>
                <div class="sc-chat__reply-bar-body">
                    <p class="sc-chat__reply-bar-label" data-sc-chat-reply-label></p>
                    <p class="sc-chat__reply-bar-snippet" data-sc-chat-reply-snippet></p>
                </div>
                <button type="button" class="sc-chat__reply-bar-clear" data-sc-chat-reply-clear aria-label="{{ __('support-chat::widget.reply_cancel') }}">×</button>
            </div>

            <form class="sc-chat__composer" data-sc-chat-form>
                <div class="sc-chat__composer-shell">
                    <label class="sr-only" for="sc-chat-input">{{ __('support-chat::widget.placeholder') }}</label>
                    <textarea
                        id="sc-chat-input"
                        class="sc-chat__input"
                        rows="1"
                        data-sc-chat-input
                        placeholder="{{ __('support-chat::widget.placeholder') }}"
                    ></textarea>

                    <p class="sc-chat__file-chip" data-sc-chat-file-chip hidden>
                        <span data-sc-chat-file-name></span>
                        <button type="button" class="sc-chat__file-clear" data-sc-chat-file-clear aria-label="{{ __('support-chat::widget.attach_clear') }}">×</button>
                    </p>

                    <div class="sc-chat__composer-bar">
                        <p class="sc-chat__attach-hint">{{ __('support-chat::widget.attach_hint') }}</p>
                        <div class="sc-chat__composer-actions">
                            <label class="sc-chat__attach" title="{{ __('support-chat::widget.attach') }}">
                                <span class="sr-only">{{ __('support-chat::widget.attach') }}</span>
                                <input
                                    type="file"
                                    class="sr-only"
                                    data-sc-chat-file
                                    accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                >
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M21.44 11.05 12.25 20.24a5.25 5.25 0 0 1-7.42-7.42l9.54-9.54a3.5 3.5 0 0 1 4.95 4.95l-9.55 9.54a1.75 1.75 0 1 1-2.47-2.47l8.48-8.49" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </label>
                            <button
                                type="submit"
                                class="sc-chat__send"
                                data-sc-chat-send
                                disabled
                                aria-label="{{ __('support-chat::widget.send') }}"
                            >
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 19V5M12 5l-5.5 5.5M12 5l5.5 5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <p class="sc-chat__note">{{ __('support-chat::widget.note') }}</p>
    </div>

    <div class="sc-chat__dock">
        <div class="sc-chat__beckon" data-sc-chat-beckon aria-hidden="true">
            <span class="sc-chat__beckon-dot"></span>
            <span class="sc-chat__beckon-text">{{ __('support-chat::widget.beckon') }}</span>
            <span class="sc-chat__beckon-tail"></span>
        </div>

        <button
            type="button"
            class="sc-chat__launcher"
            data-sc-chat-launcher
            aria-expanded="false"
            aria-label="{{ __('support-chat::widget.open') }}"
        >
            <span class="sc-chat__launcher-glow" aria-hidden="true"></span>
            <span class="sc-chat__launcher-ping" aria-hidden="true"></span>
            <span
                class="sc-chat__badge"
                data-sc-chat-badge
                hidden
                role="status"
                aria-label="{{ __('support-chat::widget.new_messages') }}"
            ></span>
            <span class="sc-chat__launcher-icon sc-chat__launcher-icon--open" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M4.5 7.75A3.75 3.75 0 0 1 8.25 4h7.5A3.75 3.75 0 0 1 19.5 7.75v4.5A3.75 3.75 0 0 1 15.75 16H12.4l-4.35 3.05c-.7.49-1.65-.05-1.55-.9l.3-2.55A3.75 3.75 0 0 1 4.5 12.25v-4.5Z"/>
                    <path d="M9 9.25h6M9 12h3.75" fill="none" stroke="#12305a" stroke-width="1.7" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="sc-chat__launcher-icon sc-chat__launcher-icon--close" hidden aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                    <path d="M6 12h12" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="sc-chat__launcher-copy">
                <span class="sc-chat__launcher-kicker">{{ __('support-chat::widget.launcher_kicker') }}</span>
                <span class="sc-chat__launcher-label">{{ __('support-chat::widget.launcher_label') }}</span>
            </span>
        </button>
    </div>
</div>

<script src="{{ route('support-chat.assets.js') }}" defer></script>
