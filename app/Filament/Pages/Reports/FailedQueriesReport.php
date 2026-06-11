<?php

namespace App\Filament\Pages\Reports;

use App\Models\Message;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FailedQueriesReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;
    protected static ?string $navigationLabel = 'Failed Queries';
    protected static ?string $title = 'Failed Queries Report';
    protected static ?string $slug = 'reports/failed-queries';
    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Reports';
    }

    protected string $view = 'filament.pages.reports.failed-queries-report';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Message::query()
                    ->where('role', 'assistant')
                    ->where(function (Builder $query) {
                        $query->whereJsonContains('meta->script', 'no_answer')
                            ->orWhere('content', 'like', '%معذرت%')
                            ->orWhere('content', 'like', '%sorry%')
                            ->orWhere('content', 'like', '%بخښنه%')
                            ->orWhere('content', 'like', '%معاف ڪجو%');
                    })
                    ->with(['chat:id,device_id,created_at'])
                    ->orderByDesc('created_at')
            )
            ->columns([
                TextColumn::make('chat.id')
                    ->label('Chat ID')
                    ->sortable(),
                TextColumn::make('userQuestion')
                    ->label('User Question')
                    ->wrap()
                    ->limit(100)
                    ->getStateUsing(function (Message $record): string {
                        $userMsg = Message::where('chat_id', $record->chat_id)
                            ->where('role', 'user')
                            ->where('id', '<', $record->id)
                            ->orderByDesc('id')
                            ->first();
                        return $userMsg?->content ?? '-';
                    }),
                TextColumn::make('content')
                    ->label('Response')
                    ->wrap()
                    ->limit(80),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('M j, Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100]);
    }
}
