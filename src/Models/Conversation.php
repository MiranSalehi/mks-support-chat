<?php

declare(strict_types=1);

namespace Miran\SupportChat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $table = 'support_chat_conversations';

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'phone_digits',
        'phone_display',
        'token_hash',
        'status',
        'entry_page_path',
        'last_message_at',
        'agent_read_message_id',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id')->latestOfMany();
    }

    public function isResumable(): bool
    {
        if ($this->status !== 'open') {
            return false;
        }

        $days = (int) config('support-chat.resume_days', 30);
        if ($days < 1 || $this->last_message_at === null) {
            return false;
        }

        return $this->last_message_at->gte(now()->subDays($days));
    }

    public function unreadVisitorCount(): int
    {
        $query = $this->messages()->where('sender', Message::SENDER_VISITOR);

        if ($this->agent_read_message_id) {
            $query->where('id', '>', (int) $this->agent_read_message_id);
        }

        return $query->count();
    }

    /**
     * Open conversations that still have visitor messages the agent has not read.
     */
    public function scopeUnreadForAgent(Builder $query): Builder
    {
        return $query
            ->where('status', 'open')
            ->whereHas('messages', function (Builder $messages): void {
                $messages
                    ->where('sender', Message::SENDER_VISITOR)
                    ->whereRaw('support_chat_messages.id > COALESCE(support_chat_conversations.agent_read_message_id, 0)');
            });
    }

    public static function unreadForAgentCount(): int
    {
        return static::query()->unreadForAgent()->count();
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim((string) $this->name)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return '•';
        }

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[1], 0, 1));
    }

    public function lastPreview(): string
    {
        $message = $this->relationLoaded('latestMessage')
            ? $this->latestMessage
            : $this->latestMessage()->first();

        if (! $message) {
            return '';
        }

        $body = trim((string) $message->body);
        if ($body !== '') {
            return $body;
        }

        if ($message->hasAttachment()) {
            return (string) $message->attachment_name;
        }

        return '';
    }

    public function markReadUpTo(?int $messageId = null): void
    {
        $latest = $messageId ?? $this->messages()->max('id');
        if ($latest === null) {
            return;
        }

        $this->forceFill(['agent_read_message_id' => (int) $latest])->save();
    }
}
