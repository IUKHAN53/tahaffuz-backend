<?php

namespace App\Filament\Resources\Chats\RelationManagers;

use App\Models\Chunk;
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
                    ->html()
                    ->wrap()
                    ->formatStateUsing(function ($state) {
                        if (! is_array($state) || empty($state)) {
                            return '<span style="color:rgb(156 163 175)">— (not from a document)</span>';
                        }

                        return collect($state)->take(4)->map(function ($c) {
                            $title = e($c['document_title'] ?? ('Document #' . ($c['document_id'] ?? '?')));
                            $section = isset($c['ordinal']) ? ' · §' . e((string) $c['ordinal']) : '';
                            $score = isset($c['score']) ? ' · ' . round(((float) $c['score']) * 100) . '% match' : '';

                            return '• ' . $title . '<span style="color:rgb(107 114 128)">' . $section . $score . '</span>';
                        })->implode('<br>');
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
                            $section = e((string) ($c['ordinal'] ?? '—'));
                            $score = isset($c['score']) ? round(((float) $c['score']) * 100, 1) . '%' : '—';

                            // Show the full matched section text, not the 240-char preview.
                            $full = ! empty($c['chunk_id']) ? optional(Chunk::find($c['chunk_id']))->content : null;
                            $text = e(trim((string) ($full ?? $c['snippet'] ?? '')));

                            // No hard-coded background — inherit the theme's text colour so
                            // it stays readable in both light and dark mode. Muted text via
                            // opacity, separators via a semi-transparent border.
                            $rows .= '<div style="margin-bottom:0.75rem;padding:0.75rem 1rem;border:1px solid rgba(128,128,128,0.3);border-radius:0.5rem">'
                                . '<div style="font-weight:600">' . ($i + 1) . '. 📄 ' . $title . '</div>'
                                . '<div style="font-size:0.8rem;opacity:0.65;margin:0.15rem 0 0.5rem">Best-matching section §' . $section . ' · relevance ' . $score . '</div>'
                                . '<div dir="auto" style="font-size:0.9rem;white-space:pre-wrap;line-height:1.7">' . ($text !== '' ? $text : '<em>no content stored</em>') . '</div>'
                                . '</div>';
                        }

                        return new HtmlString('<div style="max-height:65vh;overflow-y:auto;padding-right:0.25rem">' . $rows . '</div>');
                    }),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'asc');
    }
}
