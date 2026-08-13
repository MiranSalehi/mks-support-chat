<?php

declare(strict_types=1);

namespace Miran\SupportChat\Tests\Unit;

use Illuminate\Foundation\Auth\User;
use Miran\SupportChat\Models\Conversation;
use Miran\SupportChat\Models\Message;
use Miran\SupportChat\Support\ChatService;
use Miran\SupportChat\Tests\TestCase;

final class ChatServiceTest extends TestCase
{
    public function test_session_does_not_rotate_token(): void
    {
        $chat = app(ChatService::class);
        [$conversation, $raw] = $this->openConversation($chat);
        $hash = $conversation->token_hash;

        $found = $chat->findByRawToken($raw);
        $again = $chat->findByRawToken($raw);

        $this->assertNotNull($found);
        $this->assertNotNull($again);
        $this->assertSame($hash, $found->fresh()->token_hash);
        $this->assertSame($hash, $again->fresh()->token_hash);
        $this->assertSame($conversation->id, $found->id);
    }

    public function test_start_without_cookie_cannot_hijack_existing_email_phone_thread(): void
    {
        $chat = app(ChatService::class);
        [$original] = $this->openConversation($chat, 'Ada', 'ada@example.com', '+15551234567');

        [$second, $secondRaw, $created] = $chat->start(
            'Ada',
            'ada@example.com',
            '+15551234567',
        );

        $this->assertTrue($created);
        $this->assertNotSame($original->id, $second->id);
        $this->assertNotNull($chat->findByRawToken($secondRaw));
        $this->assertSame($original->id, $original->fresh()->id);
        $this->assertNotSame($original->fresh()->token_hash, $second->token_hash);
    }

    public function test_start_with_cookie_updates_the_same_conversation(): void
    {
        $chat = app(ChatService::class);
        [$original, $raw] = $this->openConversation($chat, 'Ada', 'ada@example.com', '+15551234567');

        [$updated, $sameRaw, $created] = $chat->start(
            'Ada Lovelace',
            'ada@example.com',
            '+15551234567',
            null,
            '/pricing',
            $raw,
        );

        $this->assertFalse($created);
        $this->assertSame($original->id, $updated->id);
        $this->assertSame($raw, $sameRaw);
        $this->assertSame($original->token_hash, $updated->fresh()->token_hash);
        $this->assertSame('Ada Lovelace', $updated->fresh()->name);
    }

    public function test_reply_to_from_another_conversation_is_rejected(): void
    {
        $chat = app(ChatService::class);
        [$one] = $this->openConversation($chat, 'Ada', 'ada@example.com', '+15551234567');
        [$two] = $this->openConversation($chat, 'Grace', 'grace@example.com', '+15557654321');

        $foreign = $one->messages()->first();
        $this->assertNotNull($foreign);

        $this->expectException(\InvalidArgumentException::class);
        $chat->addMessage($two, Message::SENDER_VISITOR, 'hi', null, (int) $foreign->id);
    }

    public function test_agent_reply_stores_agent_user_id(): void
    {
        $chat = app(ChatService::class);
        [$conversation] = $this->openConversation($chat);

        $user = new User;
        $user->forceFill([
            'name' => 'Agent',
            'email' => 'agent@example.com',
            'password' => 'secret',
        ])->save();

        $message = $chat->addMessage(
            $conversation,
            Message::SENDER_AGENT,
            'We can help.',
            null,
            null,
            (int) $user->id,
        );

        $this->assertSame(Message::SENDER_AGENT, $message->sender);
        $this->assertSame((int) $user->id, (int) $message->agent_user_id);
    }

    public function test_unread_query_matches_visitor_messages_after_agent_read(): void
    {
        $chat = app(ChatService::class);
        [$conversation] = $this->openConversation($chat);

        $this->assertSame(0, Conversation::unreadForAgentCount());

        $chat->addMessage($conversation, Message::SENDER_VISITOR, 'Hello');
        $this->assertSame(1, Conversation::unreadForAgentCount());

        $conversation->markReadUpTo();
        $this->assertSame(0, Conversation::unreadForAgentCount());

        $chat->addMessage($conversation, Message::SENDER_VISITOR, 'Still here');
        $this->assertSame(1, Conversation::unreadForAgentCount());
        $this->assertSame(1, $conversation->fresh()->unreadVisitorCount());
    }

    /**
     * @return array{0: Conversation, 1: string, 2: bool}
     */
    private function openConversation(
        ChatService $chat,
        string $name = 'Ada',
        string $email = 'ada@example.com',
        string $phone = '+15551234567',
    ): array {
        return $chat->start($name, $email, $phone);
    }
}
