<?php

namespace App\Filament\Resources\CuratedAnswers\Pages;

use App\Filament\Resources\CuratedAnswers\CuratedAnswerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCuratedAnswer extends CreateRecord
{
    protected static string $resource = CuratedAnswerResource::class;
}
