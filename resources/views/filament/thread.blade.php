<x-filament-panels::page>
    @php
        /** @var \Miran\SupportChat\Models\Conversation $record */
        $record = $this->record;
        $messages = $this->thread;
        $visitorTyping = $this->visitorTyping;
        $replyTarget = $this->replyTargetPreview();
        $attachments = app(\Miran\SupportChat\Support\AttachmentService::class);
        $accept = collect($attachments->allowedExtensions())
            ->map(static fn (string $ext): string => '.'.$ext)
            ->implode(',');
        $isOpen = $record->status === 'open';
        $hasAttachment = $this->composerAttachment !== null;
        $attachmentName = $hasAttachment ? $this->composerAttachment->getClientOriginalName() : null;
    @endphp


    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('support-chat::admin.chat.name') }}</p>
                <p class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $record->name }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('support-chat::admin.chat.email') }}</p>
                <p class="mt-1 break-all font-semibold text-gray-950 dark:text-white">{{ $record->email }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('support-chat::admin.chat.phone') }}</p>
                <p class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $record->phone_display }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('support-chat::admin.chat.status') }}</p>
                <p class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $record->status }}</p>
            </div>
        </div>

        <div
            class="overflow-hidden rounded-xl border border-gray-200 bg-white transition-shadow duration-300 dark:border-gray-700 dark:bg-gray-900"
            data-sc-chat-thread-shell
        >
            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('support-chat::admin.chat.thread') }}</h2>
            </div>

            <div
                class="max-h-[32rem] space-y-3 overflow-y-auto p-4"
                wire:poll.5s="pollThread"
                data-sc-chat-messages
            >
                @forelse ($messages as $message)
                    @php
                        $isVisitor = $message->sender === 'visitor';
                        $isAgent = $message->sender === 'agent';
                        $parent = $message->replyTo;
                    @endphp
                    <div @class([
                        'flex',
                        'justify-end' => $isAgent,
                        'justify-start' => ! $isAgent,
                    ])>
                        <div @class([
                            'max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed',
                            'bg-primary-600 text-white' => $isAgent,
                            'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-gray-100' => $isVisitor,
                            'border border-dashed border-gray-300 bg-white text-gray-700 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-200' => ! $isVisitor && ! $isAgent,
                        ])>
                            <div class="mb-1 flex items-center justify-between gap-3">
                                <p class="text-[0.65rem] font-bold uppercase tracking-wide opacity-70">
                                    {{ __('support-chat::admin.chat.sender_'.$message->sender) }}
                                </p>
                                @if ($message->sender !== 'system' && $isOpen)
                                    <button
                                        type="button"
                                        wire:click="setReplyTarget({{ $message->id }})"
                                        class="text-[0.65rem] font-semibold uppercase tracking-wide opacity-70 hover:opacity-100 hover:underline"
                                    >
                                        {{ __('support-chat::admin.chat.reply_to_message') }}
                                    </button>
                                @endif
                            </div>

                            @if ($parent)
                                <div @class([
                                    'mb-2 rounded-lg border-s-4 px-2.5 py-1.5 text-xs',
                                    'border-white/50 bg-white/15' => $isAgent,
                                    'border-primary-500 bg-white/70 dark:bg-black/20' => ! $isAgent,
                                ])>
                                    <p class="font-semibold opacity-80">
                                        {{ __('support-chat::admin.chat.sender_'.$parent->sender) }}
                                    </p>
                                    <p class="mt-0.5 line-clamp-2 opacity-90">
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
                                <p class="whitespace-pre-wrap">{{ $message->body }}</p>
                            @endif

                            @if ($message->hasAttachment())
                                @php
                                    $isImage = $message->attachmentIsImage();
                                    $sizeKb = $message->attachment_size
                                        ? number_format(((int) $message->attachment_size) / 1024, 1).' KB'
                                        : null;
                                @endphp
                                <div @class([
                                    'mt-2 overflow-hidden rounded-xl border text-start',
                                    'border-white/25 bg-black/10' => $isAgent,
                                    'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-950' => ! $isAgent,
                                ])>
                                    @if ($isImage)
                                        <a
                                            href="{{ route('support-chat.filament.attachment.preview', ['message' => $message->id]) }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="block"
                                            title="{{ $message->attachment_name }}"
                                        >
                                            <img
                                                src="{{ route('support-chat.filament.attachment.preview', ['message' => $message->id]) }}"
                                                alt="{{ $message->attachment_name }}"
                                                loading="lazy"
                                                class="block h-20 w-full max-w-[11rem] object-cover"
                                                style="max-height: 5rem; max-width: 11rem;"
                                            >
                                        </a>
                                    @endif
                                    <div class="flex items-center gap-2 px-2.5 py-2">
                                        @unless ($isImage)
                                            <div @class([
                                                'flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-sm',
                                                'bg-white/20' => $isAgent,
                                                'bg-gray-100 dark:bg-gray-800' => ! $isAgent,
                                            ])>
                                                📄
                                            </div>
                                        @endunless
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-[0.7rem] font-semibold leading-tight">
                                                {{ $message->attachment_name }}
                                            </p>
                                            @if ($sizeKb)
                                                <p class="text-[0.62rem] opacity-65">{{ $sizeKb }}</p>
                                            @endif
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="downloadAttachment({{ $message->id }})"
                                            class="shrink-0 rounded-md px-2 py-1 text-[0.65rem] font-semibold underline-offset-2 hover:underline"
                                        >
                                            {{ __('support-chat::admin.chat.download_attachment') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                            <p class="mt-1 text-[0.65rem] opacity-60">{{ $message->created_at?->format('Y-m-d H:i') }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('support-chat::admin.chat.empty_thread') }}</p>
                @endforelse

                @if ($visitorTyping)
                    <div class="flex justify-start">
                        <div class="inline-flex items-center gap-1.5 rounded-2xl border border-dashed border-gray-300 bg-white px-3.5 py-2 text-xs font-medium text-gray-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-400">
                            <span class="relative flex h-2 w-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary-400 opacity-60"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-primary-500"></span>
                            </span>
                            {{ __('support-chat::admin.chat.visitor_typing') }}
                        </div>
                    </div>
                @endif
            </div>

            <div class="sc-chat-composer-foot border-t p-3">
                @if ($replyTarget)
                    <div class="sc-chat-composer-chip mb-2 flex items-start justify-between gap-3 rounded-xl border px-3 py-2 shadow-sm">
                        <div class="min-w-0">
                            <p class="sc-chat-composer-chip__label text-[0.65rem] font-semibold uppercase tracking-wide">
                                {{ __('support-chat::admin.chat.replying_to') }}
                                {{ __('support-chat::admin.chat.sender_'.$replyTarget->sender) }}
                            </p>
                            <p class="sc-chat-composer-chip__text mt-0.5 truncate text-sm">
                                @if (filled($replyTarget->body))
                                    {{ \Illuminate\Support\Str::limit($replyTarget->body, 120) }}
                                @elseif ($replyTarget->hasAttachment())
                                    {{ $replyTarget->attachment_name }}
                                @else
                                    —
                                @endif
                            </p>
                        </div>
                        <button
                            type="button"
                            wire:click="clearReplyTarget"
                            class="sc-chat-composer-chip__cancel shrink-0 rounded-lg px-2 py-1 text-xs font-semibold"
                        >
                            {{ __('support-chat::admin.chat.reply_cancel') }}
                        </button>
                    </div>
                @endif

                @if ($isOpen)
                    <form wire:submit="sendComposer" class="sc-chat-composer">
                        @if ($hasAttachment)
                            <div class="sc-chat-composer-chip mb-2 flex items-center justify-between gap-2 rounded-xl border px-3 py-2 text-sm shadow-sm">
                                <span class="min-w-0 truncate">{{ $attachmentName }}</span>
                                <button
                                    type="button"
                                    wire:click="clearComposerAttachment"
                                    class="sc-chat-composer-chip__cancel shrink-0 rounded-md px-2 py-0.5 text-xs font-semibold"
                                >
                                    {{ __('support-chat::admin.chat.composer_remove_file') }}
                                </button>
                            </div>
                        @endif

                        <div class="sc-chat-composer-box rounded-2xl border shadow-sm">
                            <label class="sr-only" for="sc-chat-composer-body">{{ __('support-chat::admin.chat.composer_placeholder') }}</label>
                            <textarea
                                id="sc-chat-composer-body"
                                wire:model="composerBody"
                                rows="3"
                                maxlength="{{ app(\Miran\SupportChat\Support\ChatService::class)->maxMessageLength() }}"
                                placeholder="{{ __('support-chat::admin.chat.composer_placeholder') }}"
                                class="sc-chat-composer__input block w-full resize-none border-0 bg-transparent px-4 pt-3.5 pb-2 text-sm leading-relaxed focus:outline-none focus:ring-0"
                                x-data
                                x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.sendComposer() }"
                            ></textarea>

                            <div class="flex items-center justify-between gap-3 px-3 pb-3 pt-1">
                                <div class="flex items-center gap-1.5">
                                    <label
                                        class="sc-chat-composer__attach inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full transition"
                                        title="{{ __('support-chat::admin.chat.composer_attach') }}"
                                    >
                                        <span class="sr-only">{{ __('support-chat::admin.chat.composer_attach') }}</span>
                                        <x-filament::icon icon="heroicon-o-paper-clip" class="h-5 w-5" />
                                        <input
                                            type="file"
                                            class="sr-only"
                                            wire:model="composerAttachment"
                                            accept="{{ $accept }}"
                                        >
                                    </label>
                                    <span class="sc-chat-composer__hint hidden text-[0.65rem] sm:inline">
                                        {{ __('support-chat::admin.chat.composer_attach_hint', ['max' => max(1, (int) round($attachments->maxKilobytes() / 1024))]) }}
                                    </span>
                                </div>

                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="sendComposer,composerAttachment"
                                    class="sc-chat-composer__send inline-flex h-9 w-9 items-center justify-center rounded-full transition disabled:cursor-not-allowed disabled:opacity-50"
                                    title="{{ __('support-chat::admin.chat.composer_send') }}"
                                >
                                    <span class="sr-only">{{ __('support-chat::admin.chat.composer_send') }}</span>
                                    <x-filament::icon
                                        icon="heroicon-s-paper-airplane"
                                        class="h-4 w-4"
                                        wire:loading.remove
                                        wire:target="sendComposer"
                                    />
                                    <x-filament::loading-indicator
                                        class="h-4 w-4"
                                        wire:loading
                                        wire:target="sendComposer"
                                    />
                                </button>
                            </div>
                        </div>

                        @error('composerAttachment')
                            <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror
                        @error('composerBody')
                            <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror

                        <div
                            wire:loading
                            wire:target="composerAttachment"
                            class="sc-chat-composer__hint mt-2 text-xs"
                        >
                            {{ __('support-chat::admin.chat.composer_uploading') }}
                        </div>
                    </form>
                @else
                    <p class="sc-chat-composer-closed rounded-2xl border border-dashed px-4 py-3 text-sm">
                        {{ __('support-chat::admin.chat.composer_closed') }}
                    </p>
                @endif
            </div>
        </div>
    </div>

    <style>
        [data-sc-chat-thread-shell].sc-chat-thread--flash {
            box-shadow: 0 0 0 2px rgb(var(--primary-500)), 0 18px 40px -18px rgb(var(--primary-500) / 0.45);
        }

        .sc-chat-composer__input {
            field-sizing: content;
            min-height: 4.5rem;
            max-height: 10rem;
        }

        /* Explicit light/dark — Filament panel CSS does not scan plugin Blade for dark: utilities. */
        .sc-chat-composer-foot {
            border-color: rgb(229 231 235);
            background: rgb(249 250 251);
        }

        .sc-chat-composer-box {
            border-color: rgb(229 231 235);
            background: #fff;
        }

        .sc-chat-composer__input {
            color: rgb(17 24 39);
        }

        .sc-chat-composer__input::placeholder {
            color: rgb(156 163 175);
        }

        .sc-chat-composer__attach {
            color: rgb(156 163 175);
        }

        .sc-chat-composer__attach:hover {
            background: rgb(243 244 246);
            color: rgb(55 65 81);
        }

        .sc-chat-composer__hint {
            color: rgb(156 163 175);
        }

        .sc-chat-composer__send {
            background: rgb(3 7 18);
            color: #fff;
        }

        .sc-chat-composer__send:hover {
            background: #000;
        }

        .sc-chat-composer-chip {
            border-color: rgb(229 231 235);
            background: #fff;
            color: rgb(31 41 55);
        }

        .sc-chat-composer-chip__label {
            color: rgb(107 114 128);
        }

        .sc-chat-composer-chip__text {
            color: rgb(31 41 55);
        }

        .sc-chat-composer-chip__cancel {
            color: rgb(107 114 128);
        }

        .sc-chat-composer-chip__cancel:hover {
            background: rgb(243 244 246);
            color: rgb(17 24 39);
        }

        .sc-chat-composer-closed {
            border-color: rgb(209 213 219);
            background: #fff;
            color: rgb(107 114 128);
        }

        .dark .sc-chat-composer-foot {
            border-color: rgb(55 65 81);
            background: rgb(3 7 18);
        }

        .dark .sc-chat-composer-box {
            border-color: rgb(55 65 81);
            background: #000;
            box-shadow: none;
        }

        .dark .sc-chat-composer__input {
            color: rgb(243 244 246);
        }

        .dark .sc-chat-composer__input::placeholder {
            color: rgb(107 114 128);
        }

        .dark .sc-chat-composer__attach {
            color: rgb(156 163 175);
        }

        .dark .sc-chat-composer__attach:hover {
            background: rgb(255 255 255 / 0.1);
            color: rgb(243 244 246);
        }

        .dark .sc-chat-composer__hint {
            color: rgb(107 114 128);
        }

        .dark .sc-chat-composer__send {
            background: #fff;
            color: rgb(3 7 18);
        }

        .dark .sc-chat-composer__send:hover {
            background: rgb(243 244 246);
        }

        .dark .sc-chat-composer-chip {
            border-color: rgb(255 255 255 / 0.1);
            background: rgb(17 24 39);
            color: rgb(229 231 235);
            box-shadow: none;
        }

        .dark .sc-chat-composer-chip__label {
            color: rgb(156 163 175);
        }

        .dark .sc-chat-composer-chip__text {
            color: rgb(229 231 235);
        }

        .dark .sc-chat-composer-chip__cancel {
            color: rgb(209 213 219);
        }

        .dark .sc-chat-composer-chip__cancel:hover {
            background: rgb(255 255 255 / 0.1);
            color: #fff;
        }

        .dark .sc-chat-composer-closed {
            border-color: rgb(75 85 99);
            background: rgb(17 24 39);
            color: rgb(156 163 175);
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
                    // audio unavailable — ignore
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

            $wire.on('sc-chat-composer-sent', () => {
                queueMicrotask(scrollThread);
            });

            queueMicrotask(scrollThread);
        })();
    </script>
    @endscript
</x-filament-panels::page>
