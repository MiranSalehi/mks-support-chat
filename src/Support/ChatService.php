<?php

declare(strict_types=1);

namespace Miran\SupportChat\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Miran\SupportChat\Events\MessageCreated;
use Miran\SupportChat\Models\Conversation;
use Miran\SupportChat\Models\Message;

final class ChatService
{
    public const COOKIE = 'sc_chat_token';

    public function resumeDays(): int
    {
        return max(1, (int) config('support-chat.resume_days', 30));
    }

    public function maxMessageLength(): int
    {
        return max(1, (int) config('support-chat.max_message_length', 4000));
    }

    public function cookieName(): string
    {
        return (string) config('support-chat.cookie', self::COOKIE);
    }

    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: '';
    }

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function hashToken(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * @return array{0: Conversation, 1: string} conversation + raw token
     */
    public function issueToken(Conversation $conversation): array
    {
        $raw = (string) Str::uuid();
        $conversation->forceFill([
            'token_hash' => $this->hashToken($raw),
        ])->save();

        return [$conversation->fresh() ?? $conversation, $raw];
    }

    public function findByRawToken(?string $raw): ?Conversation
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $conversation = Conversation::query()
            ->where('token_hash', $this->hashToken(trim($raw)))
            ->first();

        if (! $conversation || ! $conversation->isResumable()) {
            return null;
        }

        return $conversation;
    }

    /**
     * Cookie is the only resume key. Email+phone never attach to someone else's thread.
     *
     * @return array{0: Conversation, 1: string, 2: bool} conversation, raw token, created
     */
    public function start(
        string $name,
        string $email,
        string $phone,
        ?string $greeting = null,
        ?string $entryPagePath = null,
        ?string $rawToken = null,
    ): array {
        $email = $this->normalizeEmail($email);
        $phoneDigits = $this->normalizePhone($phone);
        $phoneDisplay = trim($phone);
        $name = trim($name);

        $existing = $this->findByRawToken($rawToken);
        if ($existing) {
            $existing->forceFill([
                'name' => $name !== '' ? $name : $existing->name,
                'email' => $email !== '' ? $email : $existing->email,
                'phone_digits' => $phoneDigits !== '' ? $phoneDigits : $existing->phone_digits,
                'phone_display' => $phoneDisplay !== '' ? $phoneDisplay : $existing->phone_display,
            ])->save();

            return [$existing->load('messages'), (string) $rawToken, false];
        }

        $conversation = Conversation::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'phone_digits' => $phoneDigits,
            'phone_display' => $phoneDisplay,
            'token_hash' => $this->hashToken((string) Str::uuid()),
            'status' => 'open',
            'entry_page_path' => $entryPagePath !== null ? mb_substr($entryPagePath, 0, 255) : null,
            'last_message_at' => now(),
        ]);

        [$conversation, $raw] = $this->issueToken($conversation);

        $text = trim((string) $greeting);
        if ($text === '') {
            $text = (string) config(
                'support-chat.default_greeting',
                'Hi — how can we help? Send a message and someone from the team will reply here.'
            );
        }

        $this->addMessage($conversation, Message::SENDER_SYSTEM, $text);

        return [$conversation->fresh(['messages']) ?? $conversation, $raw, true];
    }

    public function addMessage(
        Conversation $conversation,
        string $sender,
        string $body,
        ?array $attachment = null,
        ?int $replyToId = null,
        ?int $agentUserId = null,
    ): Message {
        $replyToMessageId = $this->resolveReplyToId($conversation, $replyToId);

        $message = $conversation->messages()->create([
            'sender' => $sender,
            'agent_user_id' => $sender === Message::SENDER_AGENT ? $agentUserId : null,
            'body' => $body,
            'reply_to_message_id' => $replyToMessageId,
            'attachment_disk' => $attachment['disk'] ?? null,
            'attachment_path' => $attachment['path'] ?? null,
            'attachment_name' => $attachment['name'] ?? null,
            'attachment_mime' => $attachment['mime'] ?? null,
            'attachment_size' => $attachment['size'] ?? null,
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
        ])->save();

        if (in_array($sender, [TypingService::SIDE_VISITOR, TypingService::SIDE_AGENT], true)) {
            app(TypingService::class)->clearTyping($conversation, $sender);
        }

        $message->load('replyTo');
        $message->setRelation('conversation', $conversation->fresh());
        MessageCreated::dispatch($message);

        return $message;
    }

    public function resolveReplyToId(Conversation $conversation, ?int $replyToId): ?int
    {
        if ($replyToId === null || $replyToId < 1) {
            return null;
        }

        $exists = Message::query()
            ->whereKey($replyToId)
            ->where('conversation_id', $conversation->id)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('Invalid reply_to_id.');
        }

        return $replyToId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serializeMessages(Conversation $conversation, ?int $afterId = null): array
    {
        $query = $conversation->messages()->with('replyTo')->orderBy('id');
        if ($afterId !== null && $afterId > 0) {
            $query->where('id', '>', $afterId);
        }

        return $query->get()->map(fn (Message $message): array => $this->serializeMessage($message))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMessage(Message $message): array
    {
        $payload = [
            'id' => $message->id,
            'sender' => $message->sender,
            'body' => $message->body,
            'created_at' => optional($message->created_at)?->toIso8601String() ?? '',
            'attachment' => null,
            'reply_to' => $this->serializeReplyTo($message),
        ];

        if ($message->hasAttachment()) {
            $payload['attachment'] = [
                'name' => $message->attachment_name,
                'mime' => $message->attachment_mime,
                'size' => $message->attachment_size,
                'url' => route('support-chat.attachment', ['message' => $message->id]),
                'preview_url' => $message->attachmentIsImage()
                    ? route('support-chat.attachment.preview', ['message' => $message->id])
                    : null,
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function serializeReplyTo(Message $message): ?array
    {
        $parent = $message->relationLoaded('replyTo')
            ? $message->replyTo
            : $message->replyTo()->first();

        if (! $parent) {
            return null;
        }

        $body = trim((string) $parent->body);
        if (mb_strlen($body) > 120) {
            $body = mb_substr($body, 0, 117).'…';
        }

        $attachment = null;
        if ($parent->hasAttachment()) {
            $attachment = [
                'name' => $parent->attachment_name,
                'mime' => $parent->attachment_mime,
            ];
        }

        return [
            'id' => $parent->id,
            'sender' => $parent->sender,
            'body' => $body,
            'attachment' => $attachment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeConversation(Conversation $conversation, bool $withMessages = true): array
    {
        $payload = [
            'uuid' => $conversation->uuid,
            'name' => $conversation->name,
            'email' => $conversation->email,
            'phone' => $conversation->phone_display,
            'status' => $conversation->status,
            'last_message_at' => optional($conversation->last_message_at)?->toIso8601String(),
            'agent_read_message_id' => (int) ($conversation->agent_read_message_id ?? 0),
        ];

        if ($withMessages) {
            $payload['messages'] = $this->serializeMessages($conversation);
        }

        return $payload;
    }
}
