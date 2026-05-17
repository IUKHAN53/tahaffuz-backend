<?php

namespace App\Filament\Resources\Chats\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ChatForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id')->label('ID')->disabled(),
                TextInput::make('title')->label('Title')->disabled(),
                TextInput::make('device_id')->label('Device')->disabled(),
                TextInput::make('language')->label('Language')->disabled(),
            ]);
    }
}
