<?php

declare(strict_types=1);

namespace Miran\SupportChat\Support;

use Illuminate\Support\Facades\Schema;
use Miran\SupportChat\Models\Setting;
use Throwable;

final class TelegramSettings
{
    public const MASK = '••••';

    public function usesEnv(): bool
    {
        return $this->envToken() !== '' && $this->envChatId() !== '';
    }

    public function isEnabled(): bool
    {
        if (! $this->isReady()) {
            return false;
        }

        if ($this->usesEnv()) {
            $flag = config('support-chat.telegram.enabled');
            if ($flag === null || $flag === '') {
                return true;
            }

            return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $this->record()?->telegram_enabled;
    }

    public function isReady(): bool
    {
        return $this->botToken() !== '' && $this->chatIds() !== [];
    }

    public function botToken(): string
    {
        $env = $this->envToken();
        if ($env !== '') {
            return $env;
        }

        return trim((string) ($this->record()?->telegram_bot_token ?? ''));
    }

    /**
     * @return list<string>
     */
    public function chatIds(): array
    {
        $raw = $this->envChatId() !== ''
            ? $this->envChatId()
            : trim((string) ($this->record()?->telegram_chat_id ?? ''));

        return $this->splitChatIds($raw);
    }

    public function maskedToken(): string
    {
        $token = $this->botToken();
        if ($token === '') {
            return '';
        }

        $tail = mb_substr($token, -4);

        return self::MASK.$tail;
    }

    public function formEnabled(): bool
    {
        if ($this->usesEnv()) {
            return $this->isEnabled();
        }

        return (bool) $this->record()?->telegram_enabled;
    }

    public function formChatId(): string
    {
        if ($this->envChatId() !== '') {
            return $this->envChatId();
        }

        return trim((string) ($this->record()?->telegram_chat_id ?? ''));
    }

    /**
     * @param  array{telegram_enabled?: mixed, telegram_bot_token?: mixed, telegram_chat_id?: mixed}  $data
     */
    public function save(array $data): void
    {
        $row = $this->record() ?? new Setting;

        $token = trim((string) ($data['telegram_bot_token'] ?? ''));
        if ($token !== '' && ! str_starts_with($token, self::MASK)) {
            $row->telegram_bot_token = $token;
        }

        $row->telegram_enabled = (bool) ($data['telegram_enabled'] ?? false);
        $row->telegram_chat_id = trim((string) ($data['telegram_chat_id'] ?? ''));
        $row->save();
    }

    public function record(): ?Setting
    {
        if (! $this->tableReady()) {
            return null;
        }

        try {
            return Setting::query()->orderBy('id')->first();
        } catch (Throwable) {
            return null;
        }
    }

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable('support_chat_settings');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function splitChatIds(string $raw): array
    {
        $ids = preg_split('/\s*,\s*/', $raw) ?: [];

        return array_values(array_filter($ids, static fn (string $id): bool => $id !== ''));
    }

    private function envToken(): string
    {
        return trim((string) config('support-chat.telegram.bot_token', ''));
    }

    private function envChatId(): string
    {
        return trim((string) config('support-chat.telegram.chat_id', ''));
    }
}
