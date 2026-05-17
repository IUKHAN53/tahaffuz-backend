<?php

namespace App\Filament\Resources\Chats;

use App\Filament\Resources\Chats\Pages\ListChats;
use App\Filament\Resources\Chats\Pages\ViewChat;
use App\Filament\Resources\Chats\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\Chats\Schemas\ChatForm;
use App\Filament\Resources\Chats\Tables\ChatsTable;
use App\Models\Chat;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ChatResource extends Resource
{
    protected static ?string $model = Chat::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return ChatForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChatsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChats::route('/'),
            'view' => ViewChat::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
