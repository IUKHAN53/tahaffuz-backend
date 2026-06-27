<?php

namespace App\Filament\Resources\CuratedAnswers\Pages;

use App\Filament\Resources\CuratedAnswers\CuratedAnswerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCuratedAnswer extends EditRecord
{
    protected static string $resource = CuratedAnswerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
