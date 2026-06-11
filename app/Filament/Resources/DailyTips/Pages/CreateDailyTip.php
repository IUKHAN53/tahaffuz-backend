<?php

namespace App\Filament\Resources\DailyTips\Pages;

use App\Filament\Resources\DailyTips\DailyTipResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDailyTip extends CreateRecord
{
    protected static string $resource = DailyTipResource::class;
}
