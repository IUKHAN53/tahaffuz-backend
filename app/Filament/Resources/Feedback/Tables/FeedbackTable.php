<?php

namespace App\Filament\Resources\Feedback\Tables;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeedbackTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('rating')
                    ->label('Rating')
                    ->icon(fn (string $state): string => match ($state) {
                        'up' => 'heroicon-o-hand-thumb-up',
                        'down' => 'heroicon-o-hand-thumb-down',
                        default => 'heroicon-o-question-mark-circle',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'up' => 'success',
                        'down' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('message.content')
                    ->label('Message')
                    ->wrap()
                    ->limit(100)
                    ->searchable(),
                TextColumn::make('comment')
                    ->label('Comment')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('No comment'),
                TextColumn::make('device_id')
                    ->label('Device')
                    ->limit(12)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('rating')
                    ->options([
                        'up' => 'Positive',
                        'down' => 'Negative',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100]);
    }
}
