<?php

declare(strict_types=1);

return [
    'route_prefix' => 'support-chat',

    'cookie' => 'sc_chat_token',

    /*
    | Cookie TTL and whether an open conversation can still be restored.
    | Identity (email + phone) is never used to resume another visitor's thread.
    */
    'resume_days' => (int) env('SUPPORT_CHAT_RESUME_DAYS', 30),

    'max_message_length' => (int) env('SUPPORT_CHAT_MAX_MESSAGE_LENGTH', 4000),

    'default_greeting' => env(
        'SUPPORT_CHAT_GREETING',
        'Hi — how can we help? Send a message and someone from the team will reply here.'
    ),

    'attachment_disk' => env('SUPPORT_CHAT_ATTACHMENT_DISK', 'local'),
    'attachment_path' => env('SUPPORT_CHAT_ATTACHMENT_PATH', 'support-chat'),
    'attachment_max_kb' => (int) env('SUPPORT_CHAT_ATTACHMENT_MAX_KB', 5120),
    'attachment_extensions' => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'],

    'widget' => [
        'avatar_mark' => env('SUPPORT_CHAT_AVATAR_MARK', 'SC'),
        'quick_replies' => [],
    ],

    /*
    | Optional env override for Telegram. When bot_token is set here it wins
    | over the inbox settings form. Leave empty to configure from the panel.
    */
    'telegram' => [
        'enabled' => env('SUPPORT_CHAT_TELEGRAM_ENABLED'),
        'bot_token' => env('SUPPORT_CHAT_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('SUPPORT_CHAT_TELEGRAM_CHAT_ID'),
    ],
];
