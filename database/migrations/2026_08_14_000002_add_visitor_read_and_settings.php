<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_chat_conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('visitor_read_message_id')->nullable()->after('agent_read_message_id');
        });

        Schema::table('support_chat_conversations', function (Blueprint $table): void {
            $table->foreign('visitor_read_message_id')
                ->references('id')
                ->on('support_chat_messages')
                ->nullOnDelete();
        });

        Schema::create('support_chat_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('telegram_enabled')->default(false);
            $table->text('telegram_bot_token')->nullable();
            $table->string('telegram_chat_id', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('support_chat_conversations', function (Blueprint $table): void {
            $table->dropForeign(['visitor_read_message_id']);
            $table->dropColumn('visitor_read_message_id');
        });

        Schema::dropIfExists('support_chat_settings');
    }
};
