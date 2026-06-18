<?php

namespace App\Filament\Resources\Workers\Tables;

use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use App\Models\Worker;

class WorkersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Phone')
                    ->icon('heroicon-o-phone')
                    ->placeholder('—')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('district')
                    ->label('District')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('town')
                    ->label('Town')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('union_council')
                    ->label('Union Council')
                    ->badge()
                    ->color('success')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Registered')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
                TextColumn::make('device_id')
                    ->label('Device')
                    ->limit(12)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('district')
                    ->options(fn () => Worker::query()
                        ->whereNotNull('district')
                        ->distinct()
                        ->orderBy('district')
                        ->pluck('district', 'district')
                        ->all()),
                SelectFilter::make('union_council')
                    ->label('Union Council')
                    ->searchable()
                    ->options(fn () => Worker::query()
                        ->whereNotNull('union_council')
                        ->distinct()
                        ->orderBy('union_council')
                        ->pluck('union_council', 'union_council')
                        ->all()),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
