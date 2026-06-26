<x-filament-panels::page>
    <form wire:submit="save" class="max-w-xl space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
            <label for="memoryScope" class="text-base font-semibold text-gray-950 dark:text-white">
                Assistant memory scope
            </label>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                How the assistant remembers facts learned from conversations.
            </p>

            <select
                id="memoryScope"
                wire:model="memoryScope"
                class="mt-3 block w-full rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-800 dark:text-white"
            >
                <option value="chat">Single chat — each chat remembers only its own facts (default)</option>
                <option value="device">Cross-chat — facts are shared across all of a device's chats</option>
            </select>

            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                The scanned child's details ("current child") stay available device-wide regardless of this setting.
            </p>
        </div>

        <x-filament::button type="submit">
            Save settings
        </x-filament::button>
    </form>
</x-filament-panels::page>
