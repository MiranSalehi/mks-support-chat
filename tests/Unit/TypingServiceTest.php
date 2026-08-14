<?php

declare(strict_types=1);

namespace Miran\SupportChat\Tests\Unit;

use Miran\SupportChat\Models\Conversation;
use Miran\SupportChat\Support\TypingService;
use Miran\SupportChat\Tests\TestCase;

final class TypingServiceTest extends TestCase
{
    public function test_agent_typing_flag_is_set_and_cleared(): void
    {
        $conversation = Conversation::query()->create([
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'phone_digits' => '15551234567',
            'phone_display' => '+15551234567',
            'token_hash' => hash('sha256', 'typing-token'),
            'status' => 'open',
        ]);

        $typing = app(TypingService::class);
        $this->assertFalse($typing->isTyping($conversation, TypingService::SIDE_AGENT));

        $typing->markTyping($conversation, TypingService::SIDE_AGENT);
        $this->assertTrue($typing->isTyping($conversation, TypingService::SIDE_AGENT));

        $typing->clearTyping($conversation, TypingService::SIDE_AGENT);
        $this->assertFalse($typing->isTyping($conversation, TypingService::SIDE_AGENT));
    }
}
