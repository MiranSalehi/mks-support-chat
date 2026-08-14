<?php

declare(strict_types=1);

namespace Miran\SupportChat\Filament\Resources\ConversationResource\Pages;

use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Miran\SupportChat\Filament\Resources\ConversationResource\ConversationResource;
use Miran\SupportChat\Models\Conversation;
use Miran\SupportChat\Models\Message;
use Miran\SupportChat\Support\AttachmentService;
use Miran\SupportChat\Support\ChatService;
use Miran\SupportChat\Support\TelegramNotifier;
use Miran\SupportChat\Support\TelegramSettings;
use Miran\SupportChat\Support\TypingService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class Inbox extends Page
{
    use WithFileUploads;

    protected static string $resource = ConversationResource::class;

    protected string $view = 'support-chat::filament.inbox';

    #[Url(as: 'conversation', except: null)]
    public ?int $conversationId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    public int $lastMessageId = 0;

    public bool $visitorTyping = false;

    public ?int $replyToMessageId = null;

    public string $composerBody = '';

    /** @var TemporaryUploadedFile|null */
    public $composerAttachment = null;

    public function mount(): void
    {
        if (! in_array($this->statusFilter, ['open', 'closed', 'all'], true)) {
            $this->statusFilter = 'all';
        }

        if ($this->conversationId) {
            $this->loadThread();
        }
    }

    public function getHeading(): string | Htmlable | null
    {
        return null;
    }

    public function getTitle(): string | Htmlable
    {
        return __('support-chat::admin.nav.chat');
    }

    /**
     * @return array<string, mixed>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getMaxContentWidth(): Width | string | null
    {
        return Width::Full;
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['sc-desk-page'];
    }

    /**
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        return $this->inboxQuery()
            ->orderByDesc('last_message_at')
            ->limit(80)
            ->get();
    }

    #[Computed]
    public function activeConversation(): ?Conversation
    {
        if (! $this->conversationId) {
            return null;
        }

        return Conversation::query()->find($this->conversationId);
    }

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function thread(): Collection
    {
        $conversation = $this->activeConversation;
        if (! $conversation instanceof Conversation || $this->lastMessageId < 1) {
            return collect();
        }

        return $conversation->messages()
            ->with('replyTo')
            ->where('id', '<=', $this->lastMessageId)
            ->orderBy('id')
            ->get();
    }

    public function selectConversation(int $id): void
    {
        if ($this->conversationId === $id && $this->lastMessageId > 0) {
            return;
        }

        $this->conversationId = $id;
        $this->composerBody = '';
        $this->composerAttachment = null;
        $this->replyToMessageId = null;
        $this->resetValidation();
        $this->loadThread();
    }

    public function clearSelection(): void
    {
        $this->conversationId = null;
        $this->lastMessageId = 0;
        $this->visitorTyping = false;
        $this->replyToMessageId = null;
        $this->composerBody = '';
        $this->composerAttachment = null;
        $this->resetValidation();
        unset($this->activeConversation, $this->thread);
    }

    public function updatedConversationId(?int $id): void
    {
        unset($this->activeConversation, $this->thread);

        if ($id) {
            $this->loadThread();

            return;
        }

        $this->lastMessageId = 0;
        $this->visitorTyping = false;
        $this->replyToMessageId = null;
        $this->composerBody = '';
        $this->composerAttachment = null;
    }

    public function updatedStatusFilter(): void
    {
        if (! in_array($this->statusFilter, ['open', 'closed', 'all'], true)) {
            $this->statusFilter = 'all';
        }

        unset($this->conversations);
    }

    public function updatedSearch(): void
    {
        unset($this->conversations);
    }

    public function telegramSettingsAction(): Action
    {
        $settings = app(TelegramSettings::class);
        $locked = $settings->usesEnv();

        return Action::make('telegramSettings')
            ->label(__('support-chat::admin.telegram.action'))
            ->modalHeading(__('support-chat::admin.telegram.heading'))
            ->modalDescription($locked
                ? __('support-chat::admin.telegram.env_locked')
                : __('support-chat::admin.telegram.help'))
            ->schema([
                Toggle::make('telegram_enabled')
                    ->label(__('support-chat::admin.telegram.enabled'))
                    ->disabled($locked),
                TextInput::make('telegram_bot_token')
                    ->label(__('support-chat::admin.telegram.bot_token'))
                    ->password()
                    ->revealable()
                    ->disabled($locked)
                    ->helperText(__('support-chat::admin.telegram.bot_token_help')),
                TextInput::make('telegram_chat_id')
                    ->label(__('support-chat::admin.telegram.chat_id'))
                    ->disabled($locked)
                    ->helperText(__('support-chat::admin.telegram.chat_id_help')),
            ])
            ->fillForm(fn (): array => [
                'telegram_enabled' => $settings->formEnabled(),
                'telegram_bot_token' => $settings->maskedToken(),
                'telegram_chat_id' => $settings->formChatId(),
            ])
            ->action(function (array $data) use ($locked): void {
                if ($locked) {
                    Notification::make()
                        ->title(__('support-chat::admin.telegram.env_locked'))
                        ->warning()
                        ->send();

                    return;
                }

                if (! app(TelegramSettings::class)->tableReady()) {
                    Notification::make()
                        ->title(__('support-chat::admin.telegram.missing_table'))
                        ->danger()
                        ->send();

                    return;
                }

                app(TelegramSettings::class)->save($data);

                Notification::make()
                    ->title(__('support-chat::admin.telegram.saved'))
                    ->success()
                    ->send();
            })
            ->extraModalFooterActions([
                Action::make('sendTest')
                    ->label(__('support-chat::admin.telegram.test'))
                    ->color('gray')
                    ->action(function (): void {
                        $this->sendTelegramTest();
                    }),
            ]);
    }

    public function sendTelegramTest(): void
    {
        $ok = app(TelegramNotifier::class)->sendTest();

        Notification::make()
            ->title($ok
                ? __('support-chat::admin.telegram.test_sent')
                : __('support-chat::admin.telegram.test_failed'))
            ->{$ok ? 'success' : 'warning'}()
            ->send();
    }

    public function composerTyping(): void
    {
        $conversation = $this->activeConversation;
        if (! $conversation instanceof Conversation || $conversation->status !== 'open') {
            return;
        }

        app(TypingService::class)->markTyping($conversation, TypingService::SIDE_AGENT);
    }

    public function poll(): void
    {
        if ($this->composerAttachment !== null) {
            return;
        }

        unset($this->conversations, $this->activeConversation, $this->thread);
        $this->pollThread();
    }

    public function pollThread(): void
    {
        $conversation = $this->activeConversation;
        if (! $conversation instanceof Conversation) {
            return;
        }

        $incoming = $conversation->messages()
            ->where('id', '>', $this->lastMessageId)
            ->orderBy('id')
            ->get(['id', 'sender']);

        if ($incoming->isEmpty()) {
            $this->visitorTyping = app(TypingService::class)
                ->isTyping($conversation, TypingService::SIDE_VISITOR);

            return;
        }

        $this->lastMessageId = (int) $incoming->last()->id;
        unset($this->thread);

        $hadVisitor = $incoming->contains(
            static fn (Message $message): bool => $message->sender === Message::SENDER_VISITOR
        );

        if ($hadVisitor) {
            $this->dispatch('sc-chat-visitor-replied');
            $conversation->markReadUpTo($this->lastMessageId);
            unset($this->conversations);
        }

        $this->visitorTyping = app(TypingService::class)
            ->isTyping($conversation, TypingService::SIDE_VISITOR);
    }

    public function downloadAttachment(int $messageId): StreamedResponse
    {
        $conversation = $this->requireActive();
        $message = $conversation->messages()->whereKey($messageId)->firstOrFail();

        return app(AttachmentService::class)->download($message);
    }

    public function setReplyTarget(int $messageId): void
    {
        $conversation = $this->requireActive();
        $this->replyToMessageId = $conversation->messages()->whereKey($messageId)->exists()
            ? $messageId
            : null;
    }

    public function clearReplyTarget(): void
    {
        $this->replyToMessageId = null;
    }

    public function clearComposerAttachment(): void
    {
        $this->composerAttachment = null;
        $this->resetValidation('composerAttachment');
    }

    public function replyTargetPreview(): ?Message
    {
        if (! $this->replyToMessageId) {
            return null;
        }

        $fromThread = $this->thread->firstWhere('id', $this->replyToMessageId);
        if ($fromThread instanceof Message) {
            return $fromThread;
        }

        $conversation = $this->activeConversation;

        return $conversation?->messages()->whereKey($this->replyToMessageId)->first();
    }

    public function sendComposer(): void
    {
        $conversation = $this->requireActive();

        if ($conversation->status !== 'open') {
            Notification::make()
                ->title(__('support-chat::admin.chat.composer_closed'))
                ->warning()
                ->send();

            return;
        }

        $attachments = app(AttachmentService::class);
        $body = trim($this->composerBody);

        $this->validate([
            'composerBody' => ['nullable', 'string', 'max:'.app(ChatService::class)->maxMessageLength()],
            'composerAttachment' => [
                'nullable',
                'file',
                'max:'.$attachments->maxKilobytes(),
                'mimes:'.implode(',', $attachments->allowedExtensions()),
            ],
        ]);

        if ($body === '' && $this->composerAttachment === null) {
            Notification::make()
                ->title(__('support-chat::admin.chat.composer_empty'))
                ->warning()
                ->send();

            return;
        }

        $attachmentMeta = null;

        try {
            if ($this->composerAttachment instanceof TemporaryUploadedFile) {
                $attachments->assertSafeUpload($this->composerAttachment);
                $attachmentMeta = $attachments->store($conversation, $this->composerAttachment);
            }

            $message = app(ChatService::class)->addMessage(
                $conversation,
                Message::SENDER_AGENT,
                $body,
                $attachmentMeta,
                $this->replyToMessageId,
                auth()->id() !== null ? (int) auth()->id() : null,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->lastMessageId = max($this->lastMessageId, (int) $message->id);
        $this->composerBody = '';
        $this->composerAttachment = null;
        $this->replyToMessageId = null;
        $this->resetValidation();
        unset($this->thread, $this->conversations, $this->activeConversation);

        $conversation->markReadUpTo($this->lastMessageId);
        $this->dispatch('sc-chat-composer-sent');
    }

    public function closeConversation(): void
    {
        $this->requireActive()->update(['status' => 'closed']);
        unset($this->activeConversation, $this->conversations);
    }

    public function reopenConversation(): void
    {
        $this->requireActive()->update(['status' => 'open']);
        unset($this->activeConversation, $this->conversations);
    }

    public function deleteConversation(): void
    {
        $this->requireActive()->delete();
        $this->clearSelection();
        unset($this->conversations);

        Notification::make()
            ->title(__('support-chat::admin.chat.deleted'))
            ->success()
            ->send();
    }

    public function listTimestamp(?CarbonInterface $at): string
    {
        if ($at === null) {
            return '';
        }

        $at = $at->timezone((string) config('app.timezone'));

        if ($at->isToday()) {
            return $at->format('H:i');
        }

        if ($at->isYesterday()) {
            return __('support-chat::admin.chat.yesterday');
        }

        if ($at->isCurrentYear()) {
            return $at->format('M j');
        }

        return $at->format('Y-m-d');
    }

    protected function loadThread(): void
    {
        unset($this->activeConversation, $this->thread);

        $conversation = $this->activeConversation;
        if (! $conversation instanceof Conversation) {
            $this->conversationId = null;
            $this->lastMessageId = 0;
            $this->visitorTyping = false;

            return;
        }

        $this->lastMessageId = (int) ($conversation->messages()->max('id') ?? 0);
        $this->visitorTyping = false;
        $conversation->markReadUpTo($this->lastMessageId ?: null);
        unset($this->conversations);
        $this->dispatch('sc-chat-thread-opened');
    }

    protected function inboxQuery(): Builder
    {
        $query = Conversation::query()
            ->with('latestMessage')
            ->withCount([
                'messages as unread_count' => function (Builder $messages): void {
                    $messages
                        ->where('sender', Message::SENDER_VISITOR)
                        ->whereRaw('support_chat_messages.id > COALESCE(support_chat_conversations.agent_read_message_id, 0)');
                },
            ]);

        if ($this->statusFilter === 'open' || $this->statusFilter === 'closed') {
            $query->where('status', $this->statusFilter);
        }

        $term = trim($this->search);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone_display', 'like', $like)
                    ->orWhereHas('latestMessage', function (Builder $message) use ($like): void {
                        $message->where('body', 'like', $like);
                    });
            });
        }

        return $query;
    }

    protected function requireActive(): Conversation
    {
        $conversation = $this->activeConversation;
        if (! $conversation instanceof Conversation) {
            abort(404);
        }

        return $conversation;
    }
}
