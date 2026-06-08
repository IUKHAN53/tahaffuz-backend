<?php

namespace App\Filament\Resources\ResponseScripts\Pages;

use App\Filament\Resources\ResponseScripts\ResponseScriptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListResponseScripts extends ListRecords
{
    protected static string $resource = ResponseScriptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
