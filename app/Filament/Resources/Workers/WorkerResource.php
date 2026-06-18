<?php

namespace App\Filament\Resources\Workers;

use App\Filament\Resources\Workers\Tables\WorkersTable;
use App\Models\Worker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WorkerResource extends Resource
{
    protected static ?string $model = Worker::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'Registered Users';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'Registered Users';
    }

    public static function table(Table $table): Table
    {
        return WorkersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkers::route('/'),
        ];
    }
}
