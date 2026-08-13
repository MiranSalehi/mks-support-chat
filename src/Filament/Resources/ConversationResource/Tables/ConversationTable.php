<?php

declare(strict_types=1);

namespace Miran\SupportChat\Filament\Resources\ConversationResource\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Miran\SupportChat\Models\Conversation;

class ConversationTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label(__('support-chat::admin.common.id'))
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('support-chat::admin.chat.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('support-chat::admin.chat.email'))
                    ->searchable()
                    ->copyable(),
                TextColumn::make('phone_display')
                    ->label(__('support-chat::admin.chat.phone'))
                    ->searchable(),
                TextColumn::make('latestMessage.body')
                    ->label(__('support-chat::admin.chat.last_message'))
                    ->placeholder('—')
                    ->limit(48)
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('support-chat::admin.chat.status'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'open' ? 'success' : 'gray'),
                TextColumn::make('last_message_at')
                    ->label(__('support-chat::admin.chat.last_message_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('support-chat::admin.chat.status'))
                    ->options([
                        'open' => __('support-chat::admin.chat.status_open'),
                        'closed' => __('support-chat::admin.chat.status_closed'),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('close')
                    ->label(__('support-chat::admin.chat.close'))
                    ->icon('heroicon-o-x-mark')
                    ->color('warning')
                    ->visible(fn (Conversation $record): bool => $record->status === 'open')
                    ->requiresConfirmation()
                    ->action(fn (Conversation $record) => $record->update(['status' => 'closed'])),
                Action::make('reopen')
                    ->label(__('support-chat::admin.chat.reopen'))
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Conversation $record): bool => $record->status === 'closed')
                    ->action(fn (Conversation $record) => $record->update(['status' => 'open'])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
