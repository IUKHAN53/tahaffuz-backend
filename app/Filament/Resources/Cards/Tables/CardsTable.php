<?php

namespace App\Filament\Resources\Cards\Tables;

use App\Models\VaccinationCard;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('child_name')
                    ->label('Child')
                    ->weight('bold')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sex')
                    ->label('Sex')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('date_of_birth')
                    ->label('DOB')
                    ->placeholder('—'),
                TextColumn::make('card_number')
                    ->label('Card #')
                    ->placeholder('—')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('union_council')
                    ->label('Union Council')
                    ->badge()
                    ->color('success')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('next_due_date')
                    ->label('Next due')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),
                TextColumn::make('vaccines')
                    ->label('# Vaccines')
                    ->state(fn (VaccinationCard $c): int => count($c->vaccines ?? [])),
                TextColumn::make('created_at')
                    ->label('Scanned')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('union_council')
                    ->label('Union Council')
                    ->searchable()
                    ->options(fn () => VaccinationCard::query()
                        ->whereNotNull('union_council')
                        ->distinct()
                        ->orderBy('union_council')
                        ->pluck('union_council', 'union_council')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Details'),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
