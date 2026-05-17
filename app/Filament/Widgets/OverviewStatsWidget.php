<?php

namespace App\Filament\Widgets;

use App\Models\Chat;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Message;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class OverviewStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $today = Carbon::today();
        $weekAgo = Carbon::today()->subDays(6);
        $monthAgo = Carbon::today()->subDays(29);

        $docs = Document::count();
        $docsReady = Document::where('status', Document::STATUS_READY)->count();
        $docsFailed = Document::where('status', Document::STATUS_FAILED)->count();
        $chunks = Chunk::count();

        $chatsToday = Chat::whereDate('created_at', $today)->count();
        $chatsWeek = Chat::where('created_at', '>=', $weekAgo)->count();
        $msgsWeek = Message::where('created_at', '>=', $weekAgo)->count();

        $avgLatency = (int) round((float) Message::where('role', 'assistant')
            ->where('created_at', '>=', $weekAgo)
            ->whereNotNull('latency_ms')
            ->avg('latency_ms'));

        $weekSpark = $this->dailyCounts(Chat::query(), $weekAgo, $today);
        $msgSpark = $this->dailyCounts(Message::query()->where('role', 'assistant'), $weekAgo, $today);

        return [
            Stat::make('Documents', $docs)
                ->description("{$docsReady} ready · {$docsFailed} failed")
                ->descriptionIcon($docsFailed > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($docsFailed > 0 ? 'warning' : 'success'),

            Stat::make('Knowledge chunks', number_format($chunks))
                ->description('vector + BM25 index')
                ->descriptionIcon('heroicon-m-rectangle-stack')
                ->color('primary'),

            Stat::make('Chats (7d)', $chatsWeek)
                ->description("{$chatsToday} today")
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->chart($weekSpark)
                ->color('info'),

            Stat::make('Assistant replies (7d)', $msgsWeek)
                ->description($avgLatency > 0 ? "avg {$avgLatency} ms" : '—')
                ->descriptionIcon('heroicon-m-bolt')
                ->chart($msgSpark)
                ->color('success'),
        ];
    }

    /**
     * @return array<int,int> Daily counts inclusive between $from and $to.
     */
    protected function dailyCounts($query, Carbon $from, Carbon $to): array
    {
        $rows = $query
            ->selectRaw("strftime('%Y-%m-%d', created_at) as day, COUNT(*) as c")
            ->where('created_at', '>=', $from)
            ->groupBy('day')
            ->pluck('c', 'day')
            ->all();

        $out = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $out[] = (int) ($rows[$d->toDateString()] ?? 0);
        }
        return $out;
    }
}
