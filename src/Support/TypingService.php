<?php

declare(strict_types=1);

namespace Miran\SupportChat\Support;

use Illuminate\Support\Facades\Cache;
use Miran\SupportChat\Models\Conversation;

final class TypingService
{
    public const SIDE_VISITOR = 'visitor';

    public const SIDE_AGENT = 'agent';

    private const TTL_SECONDS = 8;

    public function markTyping(Conversation $conversation, string $side): void
    {
        Cache::put($this->key($conversation, $side), true, self::TTL_SECONDS);
    }

    public function clearTyping(Conversation $conversation, string $side): void
    {
        Cache::forget($this->key($conversation, $side));
    }

    public function isTyping(Conversation $conversation, string $side): bool
    {
        return (bool) Cache::get($this->key($conversation, $side), false);
    }

    private function key(Conversation $conversation, string $side): string
    {
        return "sc-chat:typing:{$conversation->id}:{$side}";
    }
}
