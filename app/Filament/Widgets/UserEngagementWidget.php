<?php

namespace App\Filament\Widgets;

use App\Models\Bookmark;
use App\Models\Chat;
use App\Models\Message;
use App\Models\MessageFeedback;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class UserEngagementWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;
    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $today = Carbon::today();
        $weekAgo = Carbon::today()->subDays(6);

        // Unique devices (users) this week
        $uniqueUsers = Chat::where('created_at', '>=', $weekAgo)
            ->distinct('device_id')
            ->count('device_id');

        $uniqueUsersToday = Chat::whereDate('created_at', $today)
            ->distinct('device_id')
            ->count('device_id');

        // Feedback stats
        $feedbackUp = MessageFeedback::where('rating', 'up')
            ->where('created_at', '>=', $weekAgo)
            ->count();
        $feedbackDown = MessageFeedback::where('rating', 'down')
            ->where('created_at', '>=', $weekAgo)
            ->count();
        $totalFeedback = $feedbackUp + $feedbackDown;
        $satisfactionRate = $totalFeedback > 0
            ? round(($feedbackUp / $totalFeedback) * 100)
            : 0;

        // Bookmarks
        $bookmarksWeek = Bookmark::where('created_at', '>=', $weekAgo)->count();
        $bookmarksToday = Bookmark::whereDate('created_at', $today)->count();

        // Failed queries (no answer responses)
        $failedQueries = Message::where('role', 'assistant')
            ->where('created_at', '>=', $weekAgo)
            ->where(function ($query) {
                $query->whereJsonContains('meta->script', 'no_answer')
                    ->orWhere('content', 'like', '%معذرت%')
                    ->orWhere('content', 'like', '%sorry%');
            })
            ->count();

        $totalResponses = Message::where('role', 'assistant')
            ->where('created_at', '>=', $weekAgo)
            ->count();

        $successRate = $totalResponses > 0
            ? round((($totalResponses - $failedQueries) / $totalResponses) * 100)
            : 100;

        return [
            Stat::make('Active Users (7d)', $uniqueUsers)
                ->description("{$uniqueUsersToday} today")
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),

            Stat::make('Satisfaction Rate', "{$satisfactionRate}%")
                ->description("{$feedbackUp} positive · {$feedbackDown} negative")
                ->descriptionIcon($satisfactionRate >= 80 ? 'heroicon-m-face-smile' : 'heroicon-m-face-frown')
                ->color($satisfactionRate >= 80 ? 'success' : ($satisfactionRate >= 50 ? 'warning' : 'danger')),

            Stat::make('Bookmarks (7d)', $bookmarksWeek)
                ->description("{$bookmarksToday} today")
                ->descriptionIcon('heroicon-m-bookmark')
                ->color('primary'),

            Stat::make('Answer Success Rate', "{$successRate}%")
                ->description("{$failedQueries} failed queries")
                ->descriptionIcon($successRate >= 90 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($successRate >= 90 ? 'success' : ($successRate >= 70 ? 'warning' : 'danger')),
        ];
    }
}
