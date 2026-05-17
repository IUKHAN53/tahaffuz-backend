<?php

namespace App\Filament\Widgets;

use App\Models\Chat;
use App\Models\Message;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class ChatsOverTimeChart extends ChartWidget
{
    protected ?string $heading = 'Conversations · last 30 days';

    protected ?string $pollingInterval = '60s';

    protected int | string | array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $from = Carbon::today()->subDays(29);
        $to = Carbon::today();

        $chatRows = Chat::selectRaw("strftime('%Y-%m-%d', created_at) as day, COUNT(*) as c")
            ->where('created_at', '>=', $from)
            ->groupBy('day')->pluck('c', 'day')->all();

        $msgRows = Message::where('role', 'user')
            ->selectRaw("strftime('%Y-%m-%d', created_at) as day, COUNT(*) as c")
            ->where('created_at', '>=', $from)
            ->groupBy('day')->pluck('c', 'day')->all();

        $labels = [];
        $chats = [];
        $msgs = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $labels[] = $d->format('M j');
            $chats[] = (int) ($chatRows[$key] ?? 0);
            $msgs[] = (int) ($msgRows[$key] ?? 0);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'New chats',
                    'data' => $chats,
                    'borderColor' => 'rgb(245, 158, 11)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'tension' => 0.3,
                    'fill' => true,
                ],
                [
                    'label' => 'User messages',
                    'data' => $msgs,
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.10)',
                    'tension' => 0.3,
                    'fill' => true,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
        ];
    }
}
