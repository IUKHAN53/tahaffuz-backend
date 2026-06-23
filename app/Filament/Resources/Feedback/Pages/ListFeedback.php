<?php

namespace App\Filament\Resources\Feedback\Pages;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Models\MessageFeedback;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFeedback extends ListRecords
{
    protected static string $resource = FeedbackResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->badge(MessageFeedback::count()),
            'positive' => Tab::make('Positive')
                ->badge(MessageFeedback::where('rating', 'up')->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('rating', 'up')),
            'negative' => Tab::make('Negative')
                ->badge(MessageFeedback::where('rating', 'down')->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('rating', 'down')),
        ];
    }
}
