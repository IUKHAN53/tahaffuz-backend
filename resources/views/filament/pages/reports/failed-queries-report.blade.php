<x-filament-panels::page>
    <div class="space-y-6">
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <div class="flex items-center gap-3 mb-4">
                <x-heroicon-o-exclamation-triangle style="width:1.5rem;height:1.5rem;color:#dc2626;flex:none" />
                <h2 class="text-lg font-semibold">Queries Without Answers</h2>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                This report shows questions the system couldn't answer. Use this to identify knowledge gaps that need to be addressed by adding more training documents.
            </p>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
