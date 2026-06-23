<?php

namespace App\Filament\Resources\Chats\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

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
                // Which module/document (and which section of it) the answer was
                // grounded on. Each citation = one retrieved module; ordinal is the
                // matching section within it; score is the semantic match strength.
                TextColumn::make('citations')
                    ->label('Source module · section')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('— (not from a document)')
                    ->state(function ($record) {
                        $cites = $record->citations;
                        if (! is_array($cites) || empty($cites)) {
                            return null;
                        }

                        return collect($cites)->map(function ($c) {
                            $title = $c['document_title'] ?? ('Document #' . ($c['document_id'] ?? '?'));
                            $section = isset($c['ordinal']) ? ' · §' . $c['ordinal'] : '';
                            $score = isset($c['score']) ? ' · ' . round(((float) $c['score']) * 100) . '% match' : '';

                            return $title . $section . $score;
                        })->all();
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
            ->recordActions([
                // Full grounding detail: the exact passage from each module/document
                // the model used to write this specific answer.
                Action::make('sources')
                    ->label('Sources')
                    ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                    ->color('info')
                    ->visible(fn ($record) => is_array($record->citations) && count($record->citations) > 0)
                    ->modalHeading('Sources used for this answer')
                    ->modalDescription('The retrieved modules and the exact passages the answer was grounded on, ranked by relevance.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function ($record) {
                        $rows = '';
                        foreach (array_values($record->citations) as $i => $c) {
                            $title = e($c['document_title'] ?? ('Document #' . ($c['document_id'] ?? '?')));
                            $section = $c['ordinal'] ?? '—';
                            $score = isset($c['score']) ? round(((float) $c['score']) * 100, 1) . '%' : '—';
                            $snippet = e(trim((string) ($c['snippet'] ?? '')));

                            $rows .= '<div style="margin-bottom:0.75rem;padding:0.75rem 1rem;border:1px solid rgb(229 231 235);border-radius:0.5rem;background:rgb(249 250 251)">'
                                . '<div style="font-weight:600">' . ($i + 1) . '. 📄 ' . $title . '</div>'
                                . '<div style="font-size:0.8rem;color:rgb(107 114 128);margin:0.15rem 0 0.5rem">Section §' . $section . ' · relevance ' . $score . '</div>'
                                . '<div dir="auto" style="font-size:0.9rem;white-space:pre-wrap;line-height:1.6">' . ($snippet !== '' ? $snippet : '<em>no snippet stored</em>') . '</div>'
                                . '</div>';
                        }

                        return new HtmlString('<div style="max-height:60vh;overflow:auto">' . $rows . '</div>');
                    }),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'asc');
    }
}
