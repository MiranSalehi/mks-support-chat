<?php

declare(strict_types=1);

namespace Miran\SupportChat\Listeners;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Miran\SupportChat\Events\MessageCreated;
use Miran\SupportChat\Filament\Resources\ConversationResource\ConversationResource;
use Miran\SupportChat\Models\Conversation;
use Miran\SupportChat\Models\Message;

final class NotifyPanelUsers
{
    public function handle(MessageCreated $event): void
    {
        $message = $event->message;

        if ($message->sender !== Message::SENDER_VISITOR) {
            return;
        }

        $conversation = $message->conversation;
        if (! $conversation instanceof Conversation) {
            return;
        }

        $users = $this->notifiableUsers();
        if ($users === []) {
            return;
        }

        $preview = trim((string) $message->body);
        if ($preview === '' && $message->hasAttachment()) {
            $preview = '📎 '.(string) $message->attachment_name;
        }
        $preview = mb_strimwidth($preview, 0, 140, '…');

        $title = trim((string) $conversation->name) !== ''
            ? (string) $conversation->name
            : __('support-chat::admin.chat.notification_title');

        try {
            $url = ConversationResource::getUrl('view', ['record' => $conversation->id]);
        } catch (\Throwable) {
            $url = null;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($preview)
            ->icon('heroicon-o-chat-bubble-left-right');

        if ($url) {
            $notification->actions([
                Action::make('open')
                    ->label(__('support-chat::admin.chat.open_conversation'))
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        $notification->sendToDatabase($users);
    }

    /**
     * @return list<object>
     */
    private function notifiableUsers(): array
    {
        try {
            $panel = Filament::getDefaultPanel();
        } catch (\Throwable) {
            return [];
        }

        if ($panel === null) {
            return [];
        }

        $model = config('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model)) {
            return [];
        }

        return $model::query()
            ->get()
            ->filter(function ($user) use ($panel): bool {
                return method_exists($user, 'canAccessPanel') && $user->canAccessPanel($panel);
            })
            ->values()
            ->all();
    }
}
