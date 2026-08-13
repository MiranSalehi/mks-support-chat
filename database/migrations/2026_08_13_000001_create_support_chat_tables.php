<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_chat_conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email');
            $table->string('phone_digits', 32);
            $table->string('phone_display', 64);
            $table->string('token_hash', 64)->unique();
            $table->string('status', 16)->default('open')->index();
            $table->string('entry_page_path')->nullable()->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->unsignedBigInteger('agent_read_message_id')->nullable();
            $table->timestamps();

            $table->index(['email', 'phone_digits']);
        });

        Schema::create('support_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('support_chat_conversations')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('reply_to_message_id')->nullable();
            $table->string('sender', 16);
            $table->unsignedBigInteger('agent_user_id')->nullable()->index();
            $table->text('body');
            $table->string('attachment_disk', 32)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->string('attachment_mime', 127)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->foreign('reply_to_message_id')
                ->references('id')
                ->on('support_chat_messages')
                ->nullOnDelete();
        });

        Schema::table('support_chat_conversations', function (Blueprint $table): void {
            $table->foreign('agent_read_message_id')
                ->references('id')
                ->on('support_chat_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_chat_conversations', function (Blueprint $table): void {
            $table->dropForeign(['agent_read_message_id']);
        });

        Schema::dropIfExists('support_chat_messages');
        Schema::dropIfExists('support_chat_conversations');
    }
};
