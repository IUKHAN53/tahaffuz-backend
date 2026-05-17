<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Chats\ChatResource;
use App\Models\Chat;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentChatsTableWidget extends TableWidget
{
    protected static ?string $heading = 'Recent conversations';

    protected ?string $pollingInterval = '60s';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Chat::query()->with(['knowledgeBase'])->withCount('messages')->latest('updated_at'))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('title')->label('Title')->limit(60)->placeholder('—')->wrap(),
                TextColumn::make('device_id')->label('Device')->limit(14)->copyable(),
                TextColumn::make('messages_count')->label('Msgs')->numeric()->sortable(),
                TextColumn::make('knowledgeBase.name')->label('KB'),
                TextColumn::make('language')->label('Lang')->badge(),
                TextColumn::make('updated_at')->label('Last activity')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('view')
                    ->url(fn (Chat $r) => ChatResource::getUrl('view', ['record' => $r]))
                    ->icon('heroicon-m-arrow-top-right-on-square'),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(10);
    }
}
