# mks-support-chat

Filament-backed live support chat for Laravel. Visitors talk through a storefront widget; agents reply in the Filament panel.

This is **not** a generic headless chat SDK. Without Filament nobody can answer.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- Filament 4 or 5

## Install

```bash
composer require miran/mks-support-chat
php artisan vendor:publish --tag=support-chat-migrations
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

Do **not** enable Filament `databaseNotifications()` unless Laravel's `notifications` table exists. If you want the panel bell for new visitor messages:

```bash
php artisan notifications:table
php artisan migrate
```

Then on the panel:

```php
$panel
    ->plugin(SupportChatPlugin::make())
    ->databaseNotifications()
    ->databaseNotificationsPolling('15s');
```

Drop the widget on the public layout:

```blade
<x-support-chat::widget />
```

Optional publishes:

```bash
php artisan vendor:publish --tag=support-chat-migrations
php artisan vendor:publish --tag=support-chat-config
php artisan vendor:publish --tag=support-chat-views
```

Widget CSS/JS are served from the package (`/support-chat/assets/...`). Publishing assets is not required.

## Behaviour

- Visitor identity is a httpOnly cookie (`sc_chat_token`). Restoring a thread never uses email+phone.
- Starting a chat with a valid cookie updates **that** conversation. Without a cookie, a new conversation is created — even if the email already exists.
- Agent replies store `agent_user_id`.
- The Filament nav badge is unread visitor messages, not “every open chat”.
- The admin screen is a split inbox (list + thread on one page), not a table that opens a second page.
- One tick = stored. Two ticks = the other party has read. There is no separate “delivered” state; this package polls.

## Telegram bot

Outbound alerts only. When a visitor sends a message, the bot posts a short preview (name + snippet + inbox link) to Telegram. Agent replies are not forwarded. There is no Telegram webhook and you cannot answer the chat from Telegram.

Configure it from the inbox: gear icon on the conversation list → bot token from [@BotFather](https://t.me/BotFather), chat ID, enable alerts, then **Send test**. The token is stored encrypted in `support_chat_settings`. Several agents should share one Telegram **group** (negative chat ID). Multiple chat IDs can be comma-separated.

To lock settings in the environment instead of the panel (env wins; the form becomes read-only):

```env
SUPPORT_CHAT_TELEGRAM_ENABLED=true
SUPPORT_CHAT_TELEGRAM_BOT_TOKEN=123456:ABC…
SUPPORT_CHAT_TELEGRAM_CHAT_ID=-1001234567890
```

Alerts run on `MessageCreated`. With `QUEUE_CONNECTION=sync` they send in the same request as the visitor message. Use a real queue in production so a slow Telegram API cannot stall the widget.

## Config

See `config/support-chat.php` after publishing. `widget.quick_replies` is an empty list by default.

After upgrading, publish **new** migrations again and migrate:

```bash
php artisan vendor:publish --tag=support-chat-migrations
php artisan migrate
```

The Telegram settings table and visitor read-cursor live in that second migration. Without it, the inbox gear cannot save and read receipts for agent messages will not advance.
