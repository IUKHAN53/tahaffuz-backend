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

class PopularQuestionsReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static ?string $navigationLabel = 'Popular Questions';
    protected static ?string $title = 'Popular Questions Report';
    protected static ?string $slug = 'reports/popular-questions';
    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Reports';
    }

    protected string $view = 'filament.pages.reports.popular-questions-report';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Message::query()
                    ->where('role', 'user')
                    ->selectRaw('MIN(id) as id, content, COUNT(*) as question_count')
                    ->groupBy('content')
                    ->orderByDesc('question_count')
            )
            ->columns([
                TextColumn::make('content')
                    ->label('Question')
                    ->wrap()
                    ->searchable()
                    ->limit(100),
                TextColumn::make('question_count')
                    ->label('Times Asked')
                    ->sortable()
                    ->badge()
                    ->color('primary'),
            ])
            ->defaultSort('question_count', 'desc')
            ->paginated([10, 25, 50, 100]);
    }
}
