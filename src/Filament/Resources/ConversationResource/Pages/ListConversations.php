<?php

declare(strict_types=1);

namespace Miran\SupportChat\Filament\Resources\ConversationResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Miran\SupportChat\Filament\Resources\ConversationResource\ConversationResource;

class ListConversations extends ListRecords
{
    protected static string $resource = ConversationResource::class;
}
