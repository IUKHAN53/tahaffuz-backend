<?php

namespace App\Filament\Resources\ResponseScripts;

use App\Filament\Resources\ResponseScripts\Pages\CreateResponseScript;
use App\Filament\Resources\ResponseScripts\Pages\EditResponseScript;
use App\Filament\Resources\ResponseScripts\Pages\ListResponseScripts;
use App\Filament\Resources\ResponseScripts\Schemas\ResponseScriptForm;
use App\Filament\Resources\ResponseScripts\Tables\ResponseScriptsTable;
use App\Models\ResponseScript;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ResponseScriptResource extends Resource
{
    protected static ?string $model = ResponseScript::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    public static function form(Schema $schema): Schema
    {
        return ResponseScriptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ResponseScriptsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResponseScripts::route('/'),
            'create' => CreateResponseScript::route('/create'),
            'edit' => EditResponseScript::route('/{record}/edit'),
        ];
    }
}
