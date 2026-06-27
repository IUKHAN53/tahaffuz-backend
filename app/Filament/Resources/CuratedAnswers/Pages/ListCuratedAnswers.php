<?php

namespace App\Filament\Resources\CuratedAnswers\Pages;

use App\Filament\Resources\CuratedAnswers\CuratedAnswerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCuratedAnswers extends ListRecords
{
    protected static string $resource = CuratedAnswerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
