<?php

declare(strict_types=1);

namespace Miran\SupportChat\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'support_chat_settings';

    protected $fillable = [
        'telegram_enabled',
        'telegram_bot_token',
        'telegram_chat_id',
    ];

    protected function casts(): array
    {
        return [
            'telegram_enabled' => 'boolean',
            'telegram_bot_token' => 'encrypted',
        ];
    }
}
