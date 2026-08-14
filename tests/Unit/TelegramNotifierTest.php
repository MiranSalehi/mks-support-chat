<?php

declare(strict_types=1);

namespace Miran\SupportChat\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Miran\SupportChat\Models\Message;
use Miran\SupportChat\Models\Setting;
use Miran\SupportChat\Support\ChatService;
use Miran\SupportChat\Support\TelegramNotifier;
use Miran\SupportChat\Support\TelegramSettings;
use Miran\SupportChat\Tests\TestCase;

final class TelegramNotifierTest extends TestCase
{
    public function test_disabled_settings_send_nothing(): void
    {
        Http::fake();

        $chat = app(ChatService::class);
        [$conversation] = $chat->start('Ada', 'ada@example.com', '+15551234567');
        $chat->addMessage($conversation, Message::SENDER_VISITOR, 'Hello from the shop');

        Http::assertNothingSent();
    }

    public function test_visitor_message_posts_to_telegram_when_enabled(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        Setting::query()->create([
            'telegram_enabled' => true,
            'telegram_bot_token' => '123:abc',
            'telegram_chat_id' => '999, 888',
        ]);

        $chat = app(ChatService::class);
        [$conversation] = $chat->start('Ada', 'ada@example.com', '+15551234567');
        $chat->addMessage($conversation, Message::SENDER_VISITOR, 'Need a quote');

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'https://api.telegram.org/bot123:abc/sendMessage')
                && in_array($request['chat_id'], ['999', '888'], true)
                && str_contains((string) $request['text'], 'Ada')
                && str_contains((string) $request['text'], 'Need a quote');
        });
    }

    public function test_agent_message_does_not_notify_telegram(): void
    {
        Http::fake();

        Setting::query()->create([
            'telegram_enabled' => true,
            'telegram_bot_token' => '123:abc',
            'telegram_chat_id' => '999',
        ]);

        $chat = app(ChatService::class);
        [$conversation] = $chat->start('Ada', 'ada@example.com', '+15551234567');
        $chat->addMessage($conversation, Message::SENDER_AGENT, 'On it');

        Http::assertNothingSent();
    }

    public function test_env_token_wins_over_database(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        Setting::query()->create([
            'telegram_enabled' => false,
            'telegram_bot_token' => 'db-token',
            'telegram_chat_id' => '111',
        ]);

        config([
            'support-chat.telegram.enabled' => true,
            'support-chat.telegram.bot_token' => 'env-token',
            'support-chat.telegram.chat_id' => '777',
        ]);

        $this->assertTrue(app(TelegramSettings::class)->usesEnv());
        $this->assertTrue(app(TelegramNotifier::class)->sendTest());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'botenv-token/sendMessage')
            && $request['chat_id'] === '777');
    }

    public function test_save_keeps_existing_token_when_masked(): void
    {
        Setting::query()->create([
            'telegram_enabled' => true,
            'telegram_bot_token' => 'secret-token',
            'telegram_chat_id' => '42',
        ]);

        $settings = app(TelegramSettings::class);
        $settings->save([
            'telegram_enabled' => true,
            'telegram_bot_token' => $settings->maskedToken(),
            'telegram_chat_id' => '42',
        ]);

        $this->assertSame('secret-token', $settings->record()?->fresh()?->telegram_bot_token);
    }
}
