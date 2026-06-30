<?php

namespace App\Filament\Resources\ResponseScripts\Schemas;

use App\Services\Gemini;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Throwable;

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

                Actions::make([
                    Action::make('syncTranslations')
                        ->label('Sync translations')
                        ->icon('heroicon-o-language')
                        ->color('primary')
                        ->tooltip('Auto-translate the Urdu (or English) text into all other languages. Regenerates them — review and Save afterwards; you can still edit each language by hand.')
                        ->action(function (Get $get, Set $set): void {
                            // Source = Urdu if filled, otherwise English.
                            $source = trim((string) $get('content_ur'));
                            $sourceLang = 'ur';
                            if ($source === '') {
                                $source = trim((string) $get('content_en'));
                                $sourceLang = 'en';
                            }

                            if ($source === '') {
                                Notification::make()
                                    ->title('Add the Urdu or English text first')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $targets = array_values(array_diff(['en', 'ur', 'fa', 'ps', 'sd'], [$sourceLang]));

                            try {
                                $translations = app(Gemini::class)->translateScript($source, $sourceLang, $targets);
                            } catch (Throwable $e) {
                                $translations = [];
                            }

                            if (empty($translations)) {
                                Notification::make()
                                    ->title('Translation failed')
                                    ->body('Please try again in a moment.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            foreach ($translations as $lang => $text) {
                                $set("content_{$lang}", $text);
                            }

                            Notification::make()
                                ->title('Translated to '.count($translations).' languages')
                                ->body('Review the tabs and click Save. You can edit any language by hand.')
                                ->success()
                                ->send();
                        }),
                ])->columnSpanFull(),

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

                        Tab::make('فارسی (Farsi)')
                            ->icon('heroicon-o-language')
                            ->schema([
                                Textarea::make('content_fa')
                                    ->label('Content (Farsi)')
                                    ->helperText('Optional - falls back to Urdu if empty')
                                    ->rows(6)
                                    ->extraAttributes(['dir' => 'rtl', 'style' => 'font-size: 16px; line-height: 1.8;']),
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
