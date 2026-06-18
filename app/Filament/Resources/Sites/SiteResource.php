<?php

namespace App\Filament\Resources\Sites;

use App\Filament\Resources\Sites\Tables\SitesTable;
use App\Models\Site;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $modelLabel = 'Site';

    protected static ?string $pluralModelLabel = 'Vaccination Sites';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return 'Vaccination Sites';
    }

    public static function table(Table $table): Table
    {
        return SitesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
        ];
    }
}
