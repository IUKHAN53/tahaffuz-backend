<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Models\Document;
use App\Services\Rag\Ingestor;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('knowledgeBase.name')->label('KB')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->sortable()->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => Document::STATUS_PENDING,
                        'warning' => Document::STATUS_PROCESSING,
                        'success' => Document::STATUS_READY,
                        'danger' => Document::STATUS_FAILED,
                    ])
                    ->sortable(),
                TextColumn::make('chunk_count')->label('Chunks')->numeric()->sortable(),
                TextColumn::make('size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn ($state) => $state ? round($state / 1024, 1).' KB' : '—')
                    ->sortable(),
                TextColumn::make('ingested_at')->label('Ingested')->dateTime()->sortable(),
                TextColumn::make('error')->label('Error')->limit(80)->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Document::STATUS_PENDING => 'Pending',
                    Document::STATUS_PROCESSING => 'Processing',
                    Document::STATUS_READY => 'Ready',
                    Document::STATUS_FAILED => 'Failed',
                ]),
                SelectFilter::make('knowledge_base_id')
                    ->label('Knowledge base')
                    ->relationship('knowledgeBase', 'name'),
            ])
            ->recordActions([
                Action::make('ingest')
                    ->label(fn (Document $r) => $r->status === Document::STATUS_READY ? 'Re-ingest' : 'Ingest')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (Document $record) {
                        try {
                            app(Ingestor::class)->ingest($record);
                            Notification::make()
                                ->success()
                                ->title("Ingested {$record->fresh()->chunk_count} chunks")
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Ingest failed')->body($e->getMessage())->send();
                        }
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
