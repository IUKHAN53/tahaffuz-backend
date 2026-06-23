<?php

namespace App\Filament\Resources\DailyTips\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DailyTipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title_ur')
                    ->label('Title')
                    ->limit(40)
                    ->searchable(),

                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'vaccine' => 'primary',
                        'cold_chain' => 'info',
                        'safety' => 'danger',
                        'scheduling' => 'warning',
                        default => 'gray',
                    }),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('last_sent_at')
                    ->label('Last Sent')
                    ->dateTime('M j, Y')
                    ->placeholder('Never'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options([
                        'vaccine' => 'Vaccines',
                        'cold_chain' => 'Cold Chain',
                        'safety' => 'Safety',
                        'scheduling' => 'Scheduling',
                        'general' => 'General',
                    ]),
                SelectFilter::make('is_active')
                    ->label('Status')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
