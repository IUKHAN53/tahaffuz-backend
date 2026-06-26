<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Admin settings — currently the assistant memory scope toggle.
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.settings';

    public string $memoryScope = 'chat';

    public function mount(): void
    {
        $this->memoryScope = (string) Setting::get('memory_scope', config('rag.memory.scope', 'chat'));
    }

    public function save(): void
    {
        $scope = in_array($this->memoryScope, ['chat', 'device'], true) ? $this->memoryScope : 'chat';
        Setting::put('memory_scope', $scope);

        Notification::make()
            ->title('Settings saved')
            ->body($scope === 'chat'
                ? 'Memory is now single-chat: each chat remembers only its own facts.'
                : 'Memory is now cross-chat: facts are shared across a device\'s chats.')
            ->success()
            ->send();
    }
}
