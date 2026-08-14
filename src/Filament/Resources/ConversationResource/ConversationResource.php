<?php

declare(strict_types=1);

namespace Miran\SupportChat\Filament\Resources\ConversationResource;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Miran\SupportChat\Filament\Resources\ConversationResource\Pages\ListConversations;
use Miran\SupportChat\Filament\Resources\ConversationResource\Pages\ViewConversation;
use Miran\SupportChat\Filament\Resources\ConversationResource\Tables\ConversationTable;
use Miran\SupportChat\Models\Conversation;

class ConversationResource extends Resource
{
    protected static ?string $model = Conversation::class;

    protected static ?string $slug = 'support-chat';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('support-chat::admin.nav.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('support-chat::admin.nav.chat');
    }

    public static function getModelLabel(): string
    {
        return __('support-chat::admin.models.conversation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('support-chat::admin.models.conversations');
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = Conversation::unreadForAgentCount();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('latestMessage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return ConversationTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversations::route('/'),
            'view' => ViewConversation::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
