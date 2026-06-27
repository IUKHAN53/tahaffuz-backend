<x-filament-panels::page>
    <form wire:submit="save" class="max-w-2xl">
        <x-filament::section>
            <x-slot name="heading">Assistant memory</x-slot>
            <x-slot name="description">How the assistant remembers facts learned from conversations.</x-slot>

            <div class="space-y-4">
                <div>
                    <label for="memoryScope" class="text-sm font-medium text-gray-950 dark:text-white">
                        Memory scope
                    </label>
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input.select id="memoryScope" wire:model="memoryScope">
                            <option value="chat">Single chat — each chat remembers only its own facts (default)</option>
                            <option value="device">Cross-chat — facts shared across all of a device's chats</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        The scanned child's details ("current child") stay available device-wide regardless of this setting.
                    </p>
                </div>

                <x-filament::button type="submit">
                    Save settings
                </x-filament::button>
            </div>
        </x-filament::section>
    </form>
</x-filament-panels::page>
