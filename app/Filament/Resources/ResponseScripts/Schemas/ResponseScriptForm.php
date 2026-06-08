<?php

namespace App\Filament\Resources\ResponseScripts\Schemas;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ResponseScriptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Script Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('key')
                            ->label('Key')
                            ->helperText('Unique identifier (e.g., introduction, no_answer, greeting)')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->alphaDash()
                            ->maxLength(50),

                        TextInput::make('name')
                            ->label('Name')
                            ->helperText('Human-readable name for this script')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('description')
                            ->label('Description')
                            ->helperText('When is this script used?')
                            ->columnSpanFull()
                            ->maxLength(255),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive scripts will not be used'),
                    ]),

                Tabs::make('Languages')
                    ->tabs([
                        Tab::make('اردو (Urdu)')
                            ->icon('heroicon-o-language')
                            ->schema([
                                Textarea::make('content_ur')
                                    ->label('Content (Urdu)')
                                    ->helperText('Required - this is the default language')
                                    ->required()
                                    ->rows(6)
                                    ->extraAttributes(['dir' => 'rtl', 'style' => 'font-size: 16px; line-height: 1.8;']),
                            ]),

                        Tab::make('English')
                            ->icon('heroicon-o-language')
                            ->schema([
                                Textarea::make('content_en')
                                    ->label('Content (English)')
                                    ->helperText('Optional - falls back to Urdu if empty')
                                    ->rows(6),
                            ]),

                        Tab::make('Roman Urdu')
                            ->icon('heroicon-o-language')
                            ->schema([
                                Textarea::make('content_rud')
                                    ->label('Content (Roman Urdu)')
                                    ->helperText('Optional - falls back to Urdu if empty')
                                    ->rows(6),
                            ]),

                        Tab::make('پښتو (Pashto)')
                            ->icon('heroicon-o-language')
                            ->schema([
                                Textarea::make('content_ps')
                                    ->label('Content (Pashto)')
                                    ->helperText('Optional - falls back to Urdu if empty')
                                    ->rows(6)
                                    ->extraAttributes(['dir' => 'rtl', 'style' => 'font-size: 16px; line-height: 1.8;']),
                            ]),

                        Tab::make('سنڌي (Sindhi)')
                            ->icon('heroicon-o-language')
                            ->schema([
                                Textarea::make('content_sd')
                                    ->label('Content (Sindhi)')
                                    ->helperText('Optional - falls back to Urdu if empty')
                                    ->rows(6)
                                    ->extraAttributes(['dir' => 'rtl', 'style' => 'font-size: 16px; line-height: 1.8;']),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
