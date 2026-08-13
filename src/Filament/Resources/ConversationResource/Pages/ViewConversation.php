<?php

declare(strict_types=1);

namespace Miran\SupportChat\Filament\Resources\ConversationResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Miran\SupportChat\Filament\Resources\ConversationResource\ConversationResource;
use Miran\SupportChat\Models\Conversation;
use Miran\SupportChat\Models\Message;
use Miran\SupportChat\Support\AttachmentService;
use Miran\SupportChat\Support\ChatService;
use Miran\SupportChat\Support\TypingService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * @property-read Conversation $record
 */
class ViewConversation extends ViewRecord
{
    use WithFileUploads;

    protected static string $resource = ConversationResource::class;

    protected string $view = 'support-chat::filament.thread';

    public int $lastMessageId = 0;

    public bool $visitorTyping = false;

    /** @var Collection<int, Message> */
    public Collection $thread;

    public ?int $replyToMessageId = null;

    public string $composerBody = '';

    /** @var TemporaryUploadedFile|null */
    public $composerAttachment = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->thread = $this->record->messages()->with('replyTo')->orderBy('id')->get();
        $this->lastMessageId = (int) ($this->thread->last()?->id ?? 0);
        $this->markRead();
    }

    public function pollThread(): void
    {
        $incoming = $this->record->messages()
            ->with('replyTo')
            ->where('id', '>', $this->lastMessageId)
            ->orderBy('id')
            ->get();

        if ($incoming->isNotEmpty()) {
            $hadVisitor = $incoming->contains(
                static fn (Message $message): bool => $message->sender === Message::SENDER_VISITOR
            );

            $this->thread = $this->thread->concat($incoming)->values();
            $this->lastMessageId = (int) $incoming->last()->id;

            if ($hadVisitor) {
                $this->dispatch('sc-chat-visitor-replied');
                $this->markRead();
            }
        }

        $this->visitorTyping = app(TypingService::class)
            ->isTyping($this->record, TypingService::SIDE_VISITOR);

        $this->record->refresh();
    }

    public function downloadAttachment(int $messageId): StreamedResponse
    {
        $message = $this->record->messages()->whereKey($messageId)->firstOrFail();

        return app(AttachmentService::class)->download($message);
    }

    public function setReplyTarget(int $messageId): void
    {
        $exists = $this->record->messages()->whereKey($messageId)->exists();
        $this->replyToMessageId = $exists ? $messageId : null;
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

        return $this->thread->firstWhere('id', $this->replyToMessageId)
            ?? $this->record->messages()->whereKey($this->replyToMessageId)->first();
    }

    public function sendComposer(): void
    {
        if ($this->record->status !== 'open') {
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
                $attachmentMeta = $attachments->store($this->record, $this->composerAttachment);
            }

            $message = app(ChatService::class)->addMessage(
                $this->record,
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

        $message->load('replyTo');
        $this->thread = $this->thread->push($message)->values();
        $this->lastMessageId = max($this->lastMessageId, (int) $message->id);

        $this->composerBody = '';
        $this->composerAttachment = null;
        $this->replyToMessageId = null;
        $this->resetValidation();

        $this->record->refresh();
        $this->markRead();
        $this->dispatch('sc-chat-composer-sent');
    }

    protected function markRead(): void
    {
        $this->record->markReadUpTo();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label(__('support-chat::admin.chat.close'))
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === 'open')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => 'closed']);
                    $this->record->refresh();
                }),
            Action::make('reopen')
                ->label(__('support-chat::admin.chat.reopen'))
                ->visible(fn (): bool => $this->record->status === 'closed')
                ->action(function (): void {
                    $this->record->update(['status' => 'open']);
                    $this->record->refresh();
                }),
            DeleteAction::make(),
        ];
    }
}
