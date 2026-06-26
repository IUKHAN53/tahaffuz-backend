<?php

namespace App\Filament\Resources\Memories;

use App\Filament\Resources\Memories\Tables\MemoriesTable;
use App\Models\Memory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MemoryResource extends Resource
{
    protected static ?string $model = Memory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $modelLabel = 'Memory';

    protected static ?string $pluralModelLabel = 'Memories';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Reports';
    }

    public static function table(Table $table): Table
    {
        return MemoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMemories::route('/'),
        ];
    }
}
