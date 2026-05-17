<?php

namespace App\Filament\Resources\KnowledgeBases\Pages;

use App\Filament\Resources\KnowledgeBases\KnowledgeBaseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeBase extends EditRecord
{
    protected static string $resource = KnowledgeBaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
