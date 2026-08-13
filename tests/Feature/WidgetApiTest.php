<?php

declare(strict_types=1);

namespace Miran\SupportChat\Tests\Feature;

use Miran\SupportChat\Models\Conversation;
use Miran\SupportChat\Support\ChatService;
use Miran\SupportChat\Tests\TestCase;

final class WidgetApiTest extends TestCase
{
    public function test_session_without_cookie_returns_null_conversation(): void
    {
        $this->getJson(route('support-chat.session'))
            ->assertOk()
            ->assertJson(['conversation' => null]);
    }

    public function test_session_does_not_rotate_cookie_token(): void
    {
        $chat = app(ChatService::class);
        [$conversation, $raw] = $chat->start('Ada', 'ada@example.com', '+15551234567');
        $hash = $conversation->token_hash;

        $this->withCredentials()
            ->withCookie($chat->cookieName(), $raw)
            ->getJson(route('support-chat.session'))
            ->assertOk()
            ->assertJsonPath('conversation.uuid', $conversation->uuid);

        $this->assertSame($hash, $conversation->fresh()->token_hash);

        $this->withCredentials()
            ->withCookie($chat->cookieName(), $raw)
            ->getJson(route('support-chat.session'))
            ->assertOk();

        $this->assertSame($hash, $conversation->fresh()->token_hash);
    }

    public function test_start_without_cookie_creates_a_new_conversation_for_same_identity(): void
    {
        $chat = app(ChatService::class);
        [$first] = $chat->start('Ada', 'ada@example.com', '+15551234567');

        $this->postJson(route('support-chat.start'), [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'phone' => '+15551234567',
        ])
            ->assertOk()
            ->assertJsonPath('created', true);

        $this->assertSame(2, Conversation::query()->count());
        $this->assertTrue(Conversation::query()->whereKey($first->id)->exists());
    }

    public function test_start_with_cookie_resumes_same_conversation(): void
    {
        $chat = app(ChatService::class);
        [$conversation, $raw] = $chat->start('Ada', 'ada@example.com', '+15551234567');

        $this->withCredentials()
            ->withCookie($chat->cookieName(), $raw)
            ->postJson(route('support-chat.start'), [
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'phone' => '+15551234567',
            ])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('conversation.uuid', $conversation->uuid);

        $this->assertSame(1, Conversation::query()->count());
        $this->assertSame('Ada Lovelace', $conversation->fresh()->name);
    }

    public function test_visitor_cannot_send_without_session(): void
    {
        $this->postJson(route('support-chat.send'), [
            'body' => 'hello',
        ])->assertStatus(401);
    }
}
