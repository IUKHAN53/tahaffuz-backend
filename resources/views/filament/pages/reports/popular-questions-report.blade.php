<x-filament-panels::page>
    <div class="space-y-6">
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <div class="flex items-center gap-3 mb-4">
                <x-heroicon-o-chart-bar style="width:1.5rem;height:1.5rem;color:#2563eb;flex:none" />
                <h2 class="text-lg font-semibold">Most Frequently Asked Questions</h2>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                This report shows the most common questions asked by users, helping you identify training gaps and popular topics.
            </p>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
