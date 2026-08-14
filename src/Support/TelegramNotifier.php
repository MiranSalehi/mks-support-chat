<?php

declare(strict_types=1);

namespace Miran\SupportChat\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Miran\SupportChat\Filament\Resources\ConversationResource\ConversationResource;
use Miran\SupportChat\Models\Conversation;
use Miran\SupportChat\Models\Message;
use Throwable;

final class TelegramNotifier
{
    public function __construct(
        private readonly TelegramSettings $settings,
    ) {}

    public function notifyVisitorMessage(Conversation $conversation, Message $message): void
    {
        if (! $this->settings->isEnabled()) {
            return;
        }

        $preview = trim((string) $message->body);
        if ($preview === '' && $message->hasAttachment()) {
            $preview = '📎 '.(string) $message->attachment_name;
        }
        $preview = mb_strimwidth($preview, 0, 140, '…');

        $name = trim((string) $conversation->name);
        if ($name === '') {
            $name = __('support-chat::admin.chat.notification_title');
        }

        $lines = [$name, $preview];

        try {
            $url = ConversationResource::getUrl('index').'?conversation='.$conversation->id;
            if (is_string($url) && $url !== '') {
                $lines[] = $url;
            }
        } catch (Throwable) {
            // Filament panel may not be booted (queue worker, tests).
        }

        $this->send(implode("\n", array_filter($lines)));
    }

    public function sendTest(): bool
    {
        if (! $this->settings->isReady()) {
            return false;
        }

        return $this->send(__('support-chat::admin.telegram.test_body'));
    }

    public function send(string $text): bool
    {
        $token = $this->settings->botToken();
        $chatIds = $this->settings->chatIds();
        if ($token === '' || $chatIds === []) {
            return false;
        }

        $ok = true;

        foreach ($chatIds as $chatId) {
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->asForm()
                    ->post($this->endpoint($token), [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'disable_web_page_preview' => true,
                    ]);

                if (! $response->successful()) {
                    $ok = false;
                    Log::warning('support-chat telegram notify failed', [
                        'status' => $response->status(),
                    ]);
                }
            } catch (Throwable $e) {
                $ok = false;
                Log::warning('support-chat telegram notify failed', [
                    'error' => str_replace($token, '[redacted]', $e->getMessage()),
                ]);
            }
        }

        return $ok;
    }

    private function endpoint(string $token): string
    {
        return 'https://api.telegram.org/bot'.$token.'/sendMessage';
    }
}
