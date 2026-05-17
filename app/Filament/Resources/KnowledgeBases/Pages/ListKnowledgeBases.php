<?php

namespace App\Filament\Resources\KnowledgeBases\Pages;

use App\Filament\Resources\KnowledgeBases\KnowledgeBaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeBases extends ListRecords
{
    protected static string $resource = KnowledgeBaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
