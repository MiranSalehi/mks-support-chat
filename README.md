# mks-support-chat

Filament-backed live support chat for Laravel. Visitors talk through a storefront widget; agents reply in the Filament panel.

This is **not** a generic headless chat SDK. Without Filament nobody can answer. There is no Flutter app and no language/site inbox.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- Filament 4 or 5

## Install

```bash
composer require mks-plugins/mks-support-chat
php artisan migrate
```

Register the plugin on your Filament panel:

```php
use Miran\SupportChat\Filament\SupportChatPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(SupportChatPlugin::make());
}
```

Drop the widget on the public layout:

```blade
<x-support-chat::widget />
```

Optional publishes:

```bash
php artisan vendor:publish --tag=support-chat-config
php artisan vendor:publish --tag=support-chat-views
```

Widget CSS/JS are served from the package (`/support-chat/assets/...`). Publishing assets is not required.

## Behaviour

- Visitor identity is a httpOnly cookie (`sc_chat_token`). Restoring a thread never uses email+phone.
- Starting a chat with a valid cookie updates **that** conversation. Without a cookie, a new conversation is created — even if the email already exists.
- Agent replies store `agent_user_id`.
- The Filament nav badge is unread visitor messages, not “every open chat”.

## Config

See `config/support-chat.php` after publishing. `widget.quick_replies` is an empty list by default.
