<?php

namespace App\Filament\Resources\Memories\Pages;

use App\Filament\Resources\Memories\MemoryResource;
use App\Models\Memory;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMemories extends ListRecords
{
    protected static string $resource = MemoryResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')->badge(Memory::count()),
            'child' => Tab::make('Child (card)')
                ->badge(Memory::where('kind', 'child_fact')->count())
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('kind', 'child_fact')),
            'facts' => Tab::make('Conversation facts')
                ->badge(Memory::where('kind', 'fact')->count())
                ->modifyQueryUsing(fn (Builder $q) => $q->where('kind', 'fact')),
        ];
    }
}
