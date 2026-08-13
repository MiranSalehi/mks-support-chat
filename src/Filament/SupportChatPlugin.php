<?php

declare(strict_types=1);

namespace Miran\SupportChat\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Miran\SupportChat\Filament\Resources\ConversationResource\ConversationResource;

final class SupportChatPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'support-chat';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                ConversationResource::class,
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('15s');
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
