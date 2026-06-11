<?php

namespace App\Filament\Resources\DailyTips\Pages;

use App\Filament\Resources\DailyTips\DailyTipResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDailyTips extends ListRecords
{
    protected static string $resource = DailyTipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
