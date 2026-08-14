<?php

declare(strict_types=1);

namespace Miran\SupportChat\Filament\Resources\ConversationResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Miran\SupportChat\Filament\Resources\ConversationResource\ConversationResource;

/**
 * Bookmarks to /support-chat/{id} land on the split inbox.
 */
class ViewConversation extends ViewRecord
{
    protected static string $resource = ConversationResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->redirect(
            ConversationResource::getUrl('index').'?conversation='.urlencode((string) $record),
            navigate: true,
        );
    }
}
