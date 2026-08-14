<?php

declare(strict_types=1);

namespace Miran\SupportChat\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Miran\SupportChat\Events\MessageCreated;
use Miran\SupportChat\Models\Conversation;
use Miran\SupportChat\Models\Message;
use Miran\SupportChat\Support\TelegramNotifier;

final class NotifyTelegram implements ShouldQueue
{
    public function __construct(
        private readonly TelegramNotifier $telegram,
    ) {}

    public function handle(MessageCreated $event): void
    {
        $message = $event->message;

        if ($message->sender !== Message::SENDER_VISITOR) {
            return;
        }

        $conversation = $message->conversation;
        if (! $conversation instanceof Conversation) {
            $message->loadMissing('conversation');
            $conversation = $message->conversation;
        }

        if (! $conversation instanceof Conversation) {
            return;
        }

        $this->telegram->notifyVisitorMessage($conversation, $message);
    }
}
