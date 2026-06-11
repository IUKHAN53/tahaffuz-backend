<?php

namespace App\Filament\Resources\Feedback;

use App\Filament\Resources\Feedback\Tables\FeedbackTable;
use App\Models\MessageFeedback;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FeedbackResource extends Resource
{
    protected static ?string $model = MessageFeedback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandThumbUp;

    protected static ?string $modelLabel = 'Feedback';

    protected static ?string $pluralModelLabel = 'Feedback';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'User Feedback';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Reports';
    }

    public static function table(Table $table): Table
    {
        return FeedbackTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeedback::route('/'),
        ];
    }
}
