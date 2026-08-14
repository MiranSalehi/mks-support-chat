<?php

declare(strict_types=1);

namespace Miran\SupportChat\Tests\Unit;

use Miran\SupportChat\Models\Conversation;
use Miran\SupportChat\Models\Message;
use Miran\SupportChat\Tests\TestCase;

final class ConversationTest extends TestCase
{
    public function test_initials_use_first_letters_of_two_names(): void
    {
        $conversation = new Conversation(['name' => 'Ada Lovelace']);

        $this->assertSame('AL', $conversation->initials());
    }

    public function test_initials_use_two_letters_of_a_single_name(): void
    {
        $conversation = new Conversation(['name' => 'Ada']);

        $this->assertSame('AD', $conversation->initials());
    }

    public function test_last_preview_prefers_body_then_attachment_name(): void
    {
        $conversation = Conversation::query()->create([
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'phone_digits' => '15551234567',
            'phone_display' => '+15551234567',
            'token_hash' => hash('sha256', 'token'),
            'status' => 'open',
        ]);

        $message = $conversation->messages()->create([
            'sender' => Message::SENDER_VISITOR,
            'body' => '',
            'attachment_disk' => 'local',
            'attachment_path' => 'chats/a.pdf',
            'attachment_name' => 'quote.pdf',
        ]);

        $conversation->setRelation('latestMessage', $message);

        $this->assertSame('quote.pdf', $conversation->lastPreview());

        $message->body = 'Need help';
        $this->assertSame('Need help', $conversation->lastPreview());
    }
}
