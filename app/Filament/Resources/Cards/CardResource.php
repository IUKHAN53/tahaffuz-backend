<?php

namespace App\Filament\Resources\Cards;

use App\Filament\Resources\Cards\Tables\CardsTable;
use App\Models\VaccinationCard;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CardResource extends Resource
{
    protected static ?string $model = VaccinationCard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $modelLabel = 'Vaccination Card';

    protected static ?string $pluralModelLabel = 'Scanned Cards';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return 'Scanned Cards';
    }

    public static function table(Table $table): Table
    {
        return CardsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Child')
                ->columns(3)
                ->schema([
                    TextEntry::make('child_name')->label('Child')->weight('bold')->placeholder('—'),
                    TextEntry::make('sex')->label('Sex')->badge()->placeholder('—'),
                    TextEntry::make('date_of_birth')->label('Date of Birth')->placeholder('—'),
                    TextEntry::make('father_name')->label('Father')->placeholder('—'),
                    TextEntry::make('mother_name')->label('Mother')->placeholder('—'),
                    TextEntry::make('card_number')->label('Card #')->placeholder('—')->copyable(),
                ]),

            Section::make('Area')
                ->columns(3)
                ->schema([
                    TextEntry::make('district')->label('District')->badge()->color('info')->placeholder('—'),
                    TextEntry::make('town')->label('Town')->placeholder('—'),
                    TextEntry::make('union_council')->label('Union Council')->badge()->color('success')->placeholder('—'),
                ]),

            Section::make('Vaccination')
                ->schema([
                    TextEntry::make('next_due_date')->label('Next due')->badge()->color('warning')->placeholder('—'),
                    TextEntry::make('vaccines_list')
                        ->label('Vaccines received')
                        ->state(fn (VaccinationCard $c): array => collect($c->vaccines ?? [])
                            ->map(fn ($v): string => trim((string) ($v['name'] ?? '').' — '.((string) ($v['given_date'] ?? '') ?: 'no date')))
                            ->filter()
                            ->values()
                            ->all())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('None recorded'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCards::route('/'),
        ];
    }
}
