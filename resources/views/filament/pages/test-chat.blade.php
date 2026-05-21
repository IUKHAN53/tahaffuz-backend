<x-filament-panels::page>
    <style>
        .tc-wrap { display: flex; flex-direction: column; gap: 1rem; }

        .tc-controls { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.75rem; }
        .tc-field { display: flex; flex-direction: column; gap: 0.3rem; }
        .tc-label {
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: #6b7280;
        }
        .dark .tc-label { color: #9ca3af; }
        .tc-select {
            min-width: 13rem; padding: 0.5rem 0.7rem; font-size: 0.875rem; line-height: 1.25rem;
            border: 1px solid #d1d5db; border-radius: 0.5rem; background-color: #ffffff; color: #111827;
        }
        .dark .tc-select { border-color: rgba(255,255,255,0.15); background-color: #18181b; color: #f4f4f5; }
        .tc-select:focus { outline: none; border-color: #143C6C; box-shadow: 0 0 0 1px #143C6C; }
        .tc-spacer { margin-left: auto; display: flex; align-items: center; gap: 0.75rem; }
        .tc-chatid { font-size: 0.75rem; color: #9ca3af; white-space: nowrap; }

        .tc-transcript {
            height: 26rem; overflow-y: auto; display: flex; flex-direction: column; gap: 0.75rem;
            padding: 1rem; border: 1px solid #e5e7eb; border-radius: 0.75rem; background-color: #f9fafb;
        }
        .dark .tc-transcript { border-color: rgba(255,255,255,0.1); background-color: rgba(255,255,255,0.03); }

        .tc-row { display: flex; }
        .tc-row.user { justify-content: flex-end; }
        .tc-row.bot { justify-content: flex-start; }

        .tc-bubble-user {
            max-width: 80%; padding: 0.6rem 0.9rem; font-size: 0.875rem; line-height: 1.55;
            background-color: #143C6C; color: #ffffff;
            border-radius: 1rem; border-bottom-right-radius: 0.25rem;
            white-space: pre-wrap; overflow-wrap: anywhere;
        }

        .tc-bot { max-width: 85%; display: flex; flex-direction: column; gap: 0.4rem; }
        .tc-bubble-bot {
            padding: 0.6rem 0.9rem; font-size: 0.875rem; line-height: 1.55;
            background-color: #ffffff; color: #111827; border: 1px solid #e5e7eb;
            border-radius: 1rem; border-bottom-left-radius: 0.25rem;
            white-space: pre-wrap; overflow-wrap: anywhere;
        }
        .dark .tc-bubble-bot { background-color: #27272a; color: #f4f4f5; border-color: rgba(255,255,255,0.1); }

        .tc-meta { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .tc-badge { font-size: 0.7rem; padding: 0.15rem 0.55rem; border-radius: 999px; font-weight: 600; }
        .tc-badge.latency { background-color: #e5e7eb; color: #4b5563; }
        .dark .tc-badge.latency { background-color: rgba(255,255,255,0.1); color: #d4d4d8; }
        .tc-badge.grounded { background-color: #dcfce7; color: #15803d; }
        .dark .tc-badge.grounded { background-color: rgba(34,197,94,0.18); color: #4ade80; }
        .tc-badge.refused { background-color: #fef3c7; color: #b45309; }
        .dark .tc-badge.refused { background-color: rgba(245,158,11,0.18); color: #fbbf24; }

        .tc-cites { display: flex; flex-direction: column; gap: 0.3rem; }
        .tc-cite {
            padding: 0.45rem 0.6rem; font-size: 0.75rem;
            border: 1px solid #e5e7eb; border-radius: 0.5rem; background-color: #ffffff;
        }
        .dark .tc-cite { border-color: rgba(255,255,255,0.1); background-color: rgba(255,255,255,0.04); }
        .tc-cite-head { display: flex; justify-content: space-between; gap: 0.5rem; }
        .tc-cite-title { font-weight: 600; color: #374151; }
        .dark .tc-cite-title { color: #e4e4e7; }
        .tc-cite-score { font-variant-numeric: tabular-nums; color: #9ca3af; flex-shrink: 0; }
        .tc-cite-snip {
            margin-top: 0.25rem; color: #6b7280;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .dark .tc-cite-snip { color: #9ca3af; }

        .tc-empty {
            margin: auto; text-align: center; color: #9ca3af;
            display: flex; flex-direction: column; align-items: center; gap: 0.2rem;
        }
        .tc-empty-icon { width: 2.25rem; height: 2.25rem; margin-bottom: 0.35rem; }
        .tc-empty-sub { font-size: 0.75rem; }

        .tc-thinking {
            align-self: flex-start; padding: 0.6rem 0.9rem; font-size: 0.875rem; color: #9ca3af;
            background-color: #ffffff; border: 1px solid #e5e7eb;
            border-radius: 1rem; border-bottom-left-radius: 0.25rem;
        }
        .dark .tc-thinking { background-color: #27272a; border-color: rgba(255,255,255,0.1); }

        .tc-composer { display: flex; align-items: flex-end; gap: 0.5rem; }
        .tc-textarea {
            flex: 1; resize: vertical; min-height: 3.25rem; padding: 0.6rem 0.75rem;
            font-size: 0.875rem; line-height: 1.5; font-family: inherit;
            border: 1px solid #d1d5db; border-radius: 0.5rem; background-color: #ffffff; color: #111827;
        }
        .dark .tc-textarea { border-color: rgba(255,255,255,0.15); background-color: #18181b; color: #f4f4f5; }
        .tc-textarea:focus { outline: none; border-color: #143C6C; box-shadow: 0 0 0 1px #143C6C; }
        .tc-textarea:disabled { opacity: 0.6; }
        .tc-hint { font-size: 0.75rem; color: #9ca3af; }
    </style>

    <div class="tc-wrap">
        {{-- Controls --}}
        <div class="tc-controls">
            <div class="tc-field">
                <span class="tc-label">Knowledge base</span>
                <select wire:model="knowledgeBaseId" class="tc-select">
                    @foreach ($this->getKnowledgeBaseOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="tc-field">
                <span class="tc-label">Reply language</span>
                <select wire:model="language" class="tc-select" style="min-width: 11rem;">
                    @foreach ($this->getLanguageOptions() as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="tc-spacer">
                @if ($chatId)
                    <span class="tc-chatid">Chat #{{ $chatId }}</span>
                @endif
                <x-filament::button color="gray" icon="heroicon-o-arrow-path" wire:click="startNewChat">
                    New chat
                </x-filament::button>
            </div>
        </div>

        {{-- Transcript --}}
        <div class="tc-transcript">
            @forelse ($turns as $i => $turn)
                @if ($turn['role'] === 'user')
                    <div wire:key="turn-{{ $i }}" class="tc-row user">
                        <div class="tc-bubble-user" dir="auto">{{ $turn['content'] }}</div>
                    </div>
                @else
                    <div wire:key="turn-{{ $i }}" class="tc-row bot">
                        <div class="tc-bot">
                            <div class="tc-bubble-bot" dir="auto">{{ $turn['content'] }}</div>

                            <div class="tc-meta">
                                @if (! empty($turn['latency']))
                                    <span class="tc-badge latency">{{ number_format($turn['latency']) }} ms</span>
                                @endif
                                @if (! empty($turn['refused']))
                                    <span class="tc-badge refused">Refused — no grounded context</span>
                                @elseif (! empty($turn['citations']))
                                    <span class="tc-badge grounded">
                                        Grounded · {{ count($turn['citations']) }} citation{{ count($turn['citations']) === 1 ? '' : 's' }}
                                    </span>
                                @endif
                            </div>

                            @if (! empty($turn['citations']))
                                <div class="tc-cites">
                                    @foreach ($turn['citations'] as $c)
                                        <div class="tc-cite">
                                            <div class="tc-cite-head">
                                                <span class="tc-cite-title">
                                                    {{ $c['document_title'] ?? 'Document' }} · chunk #{{ $c['ordinal'] ?? '?' }}
                                                </span>
                                                <span class="tc-cite-score">{{ number_format((float) ($c['score'] ?? 0), 4) }}</span>
                                            </div>
                                            <div class="tc-cite-snip" dir="auto">{{ $c['snippet'] ?? '' }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @empty
                <div class="tc-empty">
                    <x-filament::icon icon="heroicon-o-beaker" class="tc-empty-icon" />
                    <p>Send a message to test the assistant.</p>
                    <p class="tc-empty-sub">Try English, Urdu, or Roman Urdu — replies match the question's language.</p>
                </div>
            @endforelse

            <div wire:loading wire:target="send" class="tc-thinking">Thinking…</div>

            <div wire:key="end-{{ count($turns) }}" x-data x-init="$el.scrollIntoView()"></div>
        </div>

        {{-- Composer --}}
        <form wire:submit="send" class="tc-composer">
            <textarea
                wire:model="message"
                wire:keydown.ctrl.enter.prevent="send"
                wire:loading.attr="disabled"
                wire:target="send"
                rows="2"
                placeholder="Type a question to test…"
                class="tc-textarea"
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

        <p class="tc-hint">
            Ctrl+Enter to send. Test conversations are saved under device <strong>admin-playground</strong>
            and appear in the Chats list — “New chat” discards the current one.
        </p>
    </div>
</x-filament-panels::page>
