<?php

namespace App\Filament\Resources\Chats\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ChatsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('title')
                    ->label('Title')
                    ->placeholder('—')
                    ->limit(60)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('device_id')
                    ->label('Device')
                    ->copyable()
                    ->limit(16)
                    ->searchable(),
                TextColumn::make('messages_count')
                    ->label('Msgs')
                    ->counts('messages')
                    ->sortable(),
                TextColumn::make('knowledgeBase.name')
                    ->label('KB')
                    ->sortable(),
                TextColumn::make('language')
                    ->label('Lang')
                    ->badge(),
                TextColumn::make('updated_at')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('knowledge_base_id')
                    ->label('Knowledge base')
                    ->relationship('knowledgeBase', 'name'),
                SelectFilter::make('language')->options(['ur' => 'Urdu', 'en' => 'English']),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
