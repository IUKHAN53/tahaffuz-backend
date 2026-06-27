<?php

namespace App\Filament\Resources\CuratedAnswers;

use App\Filament\Resources\CuratedAnswers\Pages\CreateCuratedAnswer;
use App\Filament\Resources\CuratedAnswers\Pages\EditCuratedAnswer;
use App\Filament\Resources\CuratedAnswers\Pages\ListCuratedAnswers;
use App\Filament\Resources\CuratedAnswers\Schemas\CuratedAnswerForm;
use App\Filament\Resources\CuratedAnswers\Tables\CuratedAnswersTable;
use App\Models\CuratedAnswer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CuratedAnswerResource extends Resource
{
    protected static ?string $model = CuratedAnswer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $modelLabel = 'Curated Answer';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Content';
    }

    public static function form(Schema $schema): Schema
    {
        return CuratedAnswerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CuratedAnswersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCuratedAnswers::route('/'),
            'create' => CreateCuratedAnswer::route('/create'),
            'edit' => EditCuratedAnswer::route('/{record}/edit'),
        ];
    }
}
