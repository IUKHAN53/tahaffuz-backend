<x-filament-panels::page>
    <div class="space-y-4">
        {{-- Controls --}}
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-52">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Knowledge base
                </label>
                <select
                    wire:model="knowledgeBaseId"
                    class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5 dark:text-white"
                >
                    @foreach ($this->getKnowledgeBaseOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="min-w-44">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Reply language
                </label>
                <select
                    wire:model="language"
                    class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5 dark:text-white"
                >
                    @foreach ($this->getLanguageOptions() as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ms-auto flex items-center gap-3">
                @if ($chatId)
                    <span class="text-xs text-gray-400">Chat #{{ $chatId }}</span>
                @endif
                <x-filament::button color="gray" icon="heroicon-o-arrow-path" wire:click="startNewChat">
                    New chat
                </x-filament::button>
            </div>
        </div>

        {{-- Transcript --}}
        <div class="h-[26rem] space-y-3 overflow-y-auto rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
            @forelse ($turns as $i => $turn)
                @if ($turn['role'] === 'user')
                    <div wire:key="turn-{{ $i }}" class="flex justify-end">
                        <div class="max-w-[80%] rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2 text-sm text-white shadow-sm">
                            <p dir="auto" class="whitespace-pre-wrap">{{ $turn['content'] }}</p>
                        </div>
                    </div>
                @else
                    <div wire:key="turn-{{ $i }}" class="flex justify-start">
                        <div class="max-w-[85%] space-y-2">
                            <div class="rounded-2xl rounded-bl-sm border border-gray-200 bg-white px-4 py-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-gray-900 dark:text-white">
                                <p dir="auto" class="whitespace-pre-wrap">{{ $turn['content'] }}</p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                @if (! empty($turn['latency']))
                                    <span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                        {{ number_format($turn['latency']) }} ms
                                    </span>
                                @endif
                                @if (! empty($turn['refused']))
                                    <span class="rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/20 dark:text-warning-400">
                                        Refused — no grounded context
                                    </span>
                                @elseif (! empty($turn['citations']))
                                    <span class="rounded-full bg-success-100 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/20 dark:text-success-400">
                                        Grounded · {{ count($turn['citations']) }} citation{{ count($turn['citations']) === 1 ? '' : 's' }}
                                    </span>
                                @endif
                            </div>

                            @if (! empty($turn['citations']))
                                <div class="space-y-1">
                                    @foreach ($turn['citations'] as $c)
                                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-2 text-xs dark:border-white/10 dark:bg-white/5">
                                            <div class="flex items-start justify-between gap-2">
                                                <span class="font-medium text-gray-700 dark:text-gray-200">
                                                    {{ $c['document_title'] ?? 'Document' }} · chunk #{{ $c['ordinal'] ?? '?' }}
                                                </span>
                                                <span class="shrink-0 font-mono text-gray-400">
                                                    {{ number_format((float) ($c['score'] ?? 0), 4) }}
                                                </span>
                                            </div>
                                            <p dir="auto" class="mt-1 line-clamp-2 text-gray-500 dark:text-gray-400">
                                                {{ $c['snippet'] ?? '' }}
                                            </p>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @empty
                <div class="flex h-full flex-col items-center justify-center text-center text-sm text-gray-400">
                    <x-filament::icon icon="heroicon-o-beaker" class="mb-2 h-8 w-8" />
                    <p>Send a message to test the assistant.</p>
                    <p class="mt-1 text-xs">Try English, Urdu, or Roman Urdu — replies match the question's language.</p>
                </div>
            @endforelse

            <div wire:loading wire:target="send" class="flex justify-start">
                <div class="rounded-2xl rounded-bl-sm border border-gray-200 bg-white px-4 py-2 text-sm text-gray-400 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    Thinking…
                </div>
            </div>

            <div wire:key="end-{{ count($turns) }}" x-data x-init="$el.scrollIntoView()"></div>
        </div>

        {{-- Composer --}}
        <form wire:submit="send" class="flex items-end gap-2">
            <textarea
                wire:model="message"
                wire:keydown.ctrl.enter.prevent="send"
                wire:loading.attr="disabled"
                wire:target="send"
                rows="2"
                placeholder="Type a question to test…"
                class="block w-full resize-y rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-950 shadow-sm outline-none focus:border-primary-500 focus:ring-1 focus:ring-primary-500 disabled:opacity-60 dark:border-white/20 dark:bg-white/5 dark:text-white"
            ></textarea>
            <x-filament::button
                type="submit"
                icon="heroicon-o-paper-airplane"
                wire:loading.attr="disabled"
                wire:target="send"
            >
                <span wire:loading.remove wire:target="send">Send</span>
                <span wire:loading wire:target="send">Sending…</span>
            </x-filament::button>
        </form>

        <p class="text-xs text-gray-400">
            Ctrl+Enter to send. Test conversations are saved under device <span class="font-mono">admin-playground</span>
            and appear in the Chats list — “New chat” discards the current one.
        </p>
    </div>
</x-filament-panels::page>
