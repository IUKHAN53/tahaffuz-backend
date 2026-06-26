<?php

namespace App\Filament\Resources\Memories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MemoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'child_fact' ? 'Child' : 'Fact')
                    ->color(fn (string $state): string => $state === 'child_fact' ? 'info' : 'gray'),
                TextColumn::make('content')
                    ->label('Remembered')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('device_id')
                    ->label('Device')
                    ->limit(14)
                    ->searchable()
                    ->copyable(),
                TextColumn::make('chat_id')
                    ->label('Chat')
                    ->placeholder('device-wide')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Learned')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Type')
                    ->options([
                        'child_fact' => 'Child (from card)',
                        'fact' => 'Conversation fact',
                    ]),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }
}
