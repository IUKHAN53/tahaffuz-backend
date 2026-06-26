<?php

namespace App\Services;

use App\Models\Memory;
use App\Models\Setting;
use App\Models\VaccinationCard;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lightweight, mem0-style memory layer — entirely in Laravel, no external
 * service. It learns durable facts about the worker and the child they are
 * asking about, persists them, and feeds the relevant ones back into the
 * prompt so the assistant "remembers".
 *
 * Scope (admin-selectable, default 'chat'):
 *  - 'chat'   : conversation facts are remembered only within the chat they
 *               were said in (single-chat memory).
 *  - 'device' : conversation facts are shared across all of a device's chats
 *               (cross-chat memory).
 * Card-scanned child facts are always device-level ("current child") so the
 * scanner works regardless of which chat you open.
 */
class MemoryService
{
    public function __construct(protected Gemini $gemini) {}

    public function enabled(): bool
    {
        return (bool) config('rag.memory.enabled', true);
    }

    /** Effective memory scope: admin Setting wins, else the config default. */
    public function scope(): string
    {
        $scope = (string) Setting::get('memory_scope', config('rag.memory.scope', 'chat'));

        return in_array($scope, ['chat', 'device'], true) ? $scope : 'chat';
    }

    /** Whether the recall set for this device+chat is non-empty (used to bypass the shared answer cache). */
    public function has(string $deviceId, ?int $chatId): bool
    {
        return $this->enabled() && ! empty($this->recall($deviceId, $chatId));
    }

    /**
     * A prompt block describing what is already known about this worker/child,
     * so the assistant uses it instead of re-asking. Empty when nothing is known.
     */
    public function contextBlock(string $deviceId, ?int $chatId): string
    {
        if (! $this->enabled()) {
            return '';
        }

        $mems = $this->recall($deviceId, $chatId);
        if (empty($mems)) {
            return '';
        }

        $lines = array_map(fn (Memory $m): string => '- '.$m->content, $mems);

        return "WHAT YOU ALREADY KNOW ABOUT THIS WORKER / THIS CHILD (treat as true; do NOT ask again for "
            ."details that are already listed here):\n".implode("\n", $lines);
    }

    /**
     * The memories to surface for a turn: the current child's facts (always)
     * plus the most recent conversation facts within the active scope.
     *
     * @return array<int, Memory>
     */
    public function recall(string $deviceId, ?int $chatId): array
    {
        if (! $this->enabled()) {
            return [];
        }

        // Card-scanned child facts are device-level ("current child").
        $childFacts = Memory::where('device_id', $deviceId)
            ->where('kind', Memory::KIND_CHILD)
            ->latest('id')
            ->get();

        // Conversation facts honor the scope: in 'chat' scope only this chat's.
        $convoFacts = Memory::where('device_id', $deviceId)
            ->where('kind', Memory::KIND_FACT)
            ->when($this->scope() === 'chat', fn ($q) => $q->where('chat_id', $chatId))
            ->latest('id')
            ->limit((int) config('rag.memory.recall_facts', 6))
            ->get();

        return $childFacts->concat($convoFacts)->all();
    }

    /** Memories for an admin/app listing, newest first (optionally one chat). */
    public function list(string $deviceId, ?int $chatId = null): \Illuminate\Support\Collection
    {
        return Memory::where('device_id', $deviceId)
            ->when($chatId !== null, fn ($q) => $q->where(fn ($q) => $q->where('chat_id', $chatId)->orWhereNull('chat_id')))
            ->latest('id')
            ->get();
    }

    /** Forget memories for a device (optionally just one chat's conversation facts). */
    public function clear(string $deviceId, ?int $chatId = null): int
    {
        $q = Memory::where('device_id', $deviceId);
        if ($chatId !== null) {
            $q->where('chat_id', $chatId);
        }

        return $q->delete();
    }

    /**
     * Seed memory directly from a scanned card (no LLM). Child facts are
     * REPLACED so memory always reflects the current child for this device.
     */
    public function rememberCard(string $deviceId, ?int $workerId, VaccinationCard $card): void
    {
        if (! $this->enabled()) {
            return;
        }

        Memory::where('device_id', $deviceId)->where('kind', Memory::KIND_CHILD)->delete();

        $facts = [];
        if ($card->child_name) {
            $facts[] = "The child being asked about is named {$card->child_name}.";
        }
        if ($card->date_of_birth) {
            $facts[] = "The child's date of birth is {$card->date_of_birth}.";
        }
        if ($card->sex) {
            $facts[] = "The child is a {$card->sex}.";
        }
        $given = collect((array) $card->vaccines)
            ->map(fn ($v) => is_array($v) ? trim((string) ($v['name'] ?? '')) : '')
            ->filter()
            ->implode(', ');
        if ($given !== '') {
            $facts[] = "Vaccines this child has already received: {$given}.";
        }
        if ($card->next_due_date) {
            $facts[] = "The child's next vaccination is due on {$card->next_due_date}.";
        }
        if ($card->union_council) {
            $facts[] = "This child's union council is {$card->union_council}.";
        }

        foreach ($facts as $content) {
            $this->store($deviceId, $workerId, null, Memory::KIND_CHILD, $content);
        }
    }

    /**
     * Extract durable facts from a completed turn and store them, tagged to the
     * chat. Best-effort and side-effect free on failure — runs AFTER the
     * response is sent.
     */
    public function rememberFromTurn(string $deviceId, ?int $workerId, ?int $chatId, string $userText, string $assistantText): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $facts = $this->gemini->extractMemories($userText, $assistantText);
        } catch (Throwable $e) {
            Log::debug('Memory extraction failed', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($facts as $content) {
            $this->store($deviceId, $workerId, $chatId, Memory::KIND_FACT, (string) $content);
        }

        $this->pruneFacts($deviceId);
    }

    /** Store one memory, updating a near-duplicate in place instead of adding it twice. */
    protected function store(string $deviceId, ?int $workerId, ?int $chatId, string $kind, string $content): void
    {
        $content = trim(preg_replace('/\s+/u', ' ', $content) ?? '');
        if ($content === '' || mb_strlen($content) > 400) {
            return;
        }

        // Dedupe within the same scope bucket (same chat for facts, device-wide for child facts).
        $existing = Memory::where('device_id', $deviceId)
            ->when($kind === Memory::KIND_FACT && $chatId !== null, fn ($q) => $q->where('chat_id', $chatId))
            ->get();
        foreach ($existing as $e) {
            similar_text(mb_strtolower($e->content), mb_strtolower($content), $pct);
            if ($pct >= 82.0) {
                $e->forceFill([
                    'content' => $content,
                    'kind' => $kind,
                    'chat_id' => $chatId,
                    'last_used_at' => now(),
                ])->save();

                return;
            }
        }

        Memory::create([
            'device_id' => $deviceId,
            'worker_id' => $workerId,
            'chat_id' => $chatId,
            'kind' => $kind,
            'content' => $content,
            'source_chat_id' => $chatId,
            'last_used_at' => now(),
        ]);
    }

    /** Keep only the most recent N conversation facts per device. */
    protected function pruneFacts(string $deviceId): void
    {
        $max = (int) config('rag.memory.max_facts', 30);
        $ids = Memory::where('device_id', $deviceId)
            ->where('kind', Memory::KIND_FACT)
            ->latest('id')
            ->skip($max)
            ->take(1000)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            Memory::whereIn('id', $ids)->delete();
        }
    }
}
