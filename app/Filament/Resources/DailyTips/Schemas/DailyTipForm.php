<?php

namespace App\Filament\Resources\DailyTips\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class DailyTipForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Daily Tip')
                ->description('Create tips that will be sent as push notifications to users.')
                ->schema([
                    Select::make('category')
                        ->label('Category')
                        ->options([
                            'vaccine' => 'Vaccines',
                            'cold_chain' => 'Cold Chain',
                            'safety' => 'Safety',
                            'scheduling' => 'Scheduling',
                            'general' => 'General',
                        ])
                        ->native(false),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Only active tips will be sent in notifications.'),

                    Tabs::make('Content')
                        ->tabs([
                            Tab::make('Urdu')
                                ->icon('heroicon-o-language')
                                ->schema([
                                    TextInput::make('title_ur')
                                        ->label('Title (Urdu)')
                                        ->required()
                                        ->maxLength(100)
                                        ->extraInputAttributes(['dir' => 'rtl']),

                                    Textarea::make('content_ur')
                                        ->label('Content (Urdu)')
                                        ->required()
                                        ->rows(4)
                                        ->extraInputAttributes(['dir' => 'rtl']),
                                ]),

                            Tab::make('English')
                                ->icon('heroicon-o-language')
                                ->schema([
                                    TextInput::make('title_en')
                                        ->label('Title (English)')
                                        ->maxLength(100),

                                    Textarea::make('content_en')
                                        ->label('Content (English)')
                                        ->rows(4),
                                ]),
                        ]),
                ]),
        ]);
    }
}
