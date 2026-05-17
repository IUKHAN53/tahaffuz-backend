<?php

namespace App\Filament\Resources\Chats\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Conversation';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('content')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('role')
                    ->label('')
                    ->badge()
                    ->colors([
                        'success' => 'user',
                        'info' => 'assistant',
                        'gray' => 'system',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'user' => '👤 User',
                        'assistant' => '🤖 Tahaffuz',
                        default => $state,
                    }),
                TextColumn::make('content')
                    ->label('Message')
                    ->wrap()
                    ->html(false)
                    ->limit(500),
                TextColumn::make('citations')
                    ->label('Cited')
                    ->formatStateUsing(function ($state) {
                        if (! is_array($state) || empty($state)) {
                            return '—';
                        }
                        return collect($state)
                            ->take(3)
                            ->map(fn ($c) => $c['document_title'] ?? 'doc')
                            ->unique()
                            ->implode(', ');
                    }),
                TextColumn::make('latency_ms')
                    ->label('ms')
                    ->placeholder('—')
                    ->numeric(),
                TextColumn::make('created_at')
                    ->label('When')
                    ->since(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'asc');
    }
}
