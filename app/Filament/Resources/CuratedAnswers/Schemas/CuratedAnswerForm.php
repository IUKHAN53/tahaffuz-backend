<?php

namespace App\Filament\Resources\CuratedAnswers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CuratedAnswerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('question')
                ->label('Question / trigger phrase')
                ->helperText('When a worker asks something matching this, the assistant returns the answer below instead of generating one.')
                ->required()
                ->rows(2),
            Textarea::make('answer')
                ->label('Approved answer')
                ->required()
                ->rows(6),
            Select::make('language')
                ->label('Language')
                ->placeholder('Any language')
                ->options([
                    'en' => 'English',
                    'ur' => 'Urdu',
                    'ps' => 'Pashto',
                    'sd' => 'Sindhi',
                    'fa' => 'Farsi',
                ]),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ]);
    }
}
