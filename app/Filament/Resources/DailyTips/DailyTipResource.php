<?php

namespace App\Filament\Resources\DailyTips;

use App\Filament\Resources\DailyTips\Schemas\DailyTipForm;
use App\Filament\Resources\DailyTips\Tables\DailyTipsTable;
use App\Models\DailyTip;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DailyTipResource extends Resource
{
    protected static ?string $model = DailyTip::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Daily Tips';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Content';
    }

    public static function form(Schema $schema): Schema
    {
        return DailyTipForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DailyTipsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyTips::route('/'),
            'create' => Pages\CreateDailyTip::route('/create'),
            'edit' => Pages\EditDailyTip::route('/{record}/edit'),
        ];
    }
}
