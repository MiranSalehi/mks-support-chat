<?php

declare(strict_types=1);

namespace Miran\SupportChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    public const SENDER_VISITOR = 'visitor';

    public const SENDER_AGENT = 'agent';

    public const SENDER_SYSTEM = 'system';

    protected $table = 'support_chat_messages';

    protected $fillable = [
        'conversation_id',
        'reply_to_message_id',
        'sender',
        'agent_user_id',
        'body',
        'attachment_disk',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
    ];

    protected function casts(): array
    {
        return [
            'attachment_size' => 'integer',
            'reply_to_message_id' => 'integer',
            'agent_user_id' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'agent_user_id');
    }

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path) && filled($this->attachment_disk);
    }

    public function attachmentIsImage(): bool
    {
        $mime = strtolower((string) $this->attachment_mime);

        return $this->hasAttachment() && str_starts_with($mime, 'image/');
    }
}
