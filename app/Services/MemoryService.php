<?php

namespace App\Services;

use App\Models\Memory;
use App\Models\VaccinationCard;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lightweight, mem0-style memory layer — entirely in Laravel, no external
 * service. It learns durable facts about the worker and the child they are
 * asking about, persists them per device, and feeds the relevant ones back
 * into the prompt so the assistant "remembers" across turns and sessions.
 *
 * Per-device memory sets are tiny (one current child + a handful of facts),
 * so recall is just "the current child's facts + the most recent facts" — no
 * embedding/vector search needed, which keeps it free and instant.
 */
class MemoryService
{
    public function __construct(protected Gemini $gemini) {}

    public function enabled(): bool
    {
        return (bool) config('rag.memory.enabled', true);
    }

    /** Whether this device has any stored memory (used to bypass the shared answer cache). */
    public function has(string $deviceId): bool
    {
        return $this->enabled() && Memory::where('device_id', $deviceId)->exists();
    }

    /**
     * A prompt block describing what is already known about this worker/child,
     * so the assistant uses it instead of re-asking. Empty string when nothing
     * is known or memory is disabled.
     */
    public function contextBlock(string $deviceId): string
    {
        if (! $this->enabled()) {
            return '';
        }

        $mems = $this->recall($deviceId);
        if (empty($mems)) {
            return '';
        }

        $lines = array_map(fn (Memory $m): string => '- '.$m->content, $mems);

        return "WHAT YOU ALREADY KNOW ABOUT THIS WORKER / THIS CHILD (treat as true; do NOT ask again for "
            ."details that are already listed here):\n".implode("\n", $lines);
    }

    /**
     * The memories to surface for a turn: the current child's facts plus the
     * most recent conversation facts, capped.
     *
     * @return array<int, Memory>
     */
    public function recall(string $deviceId): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $childFacts = Memory::where('device_id', $deviceId)
            ->where('kind', Memory::KIND_CHILD)
            ->latest('id')
            ->get();

        $convoFacts = Memory::where('device_id', $deviceId)
            ->where('kind', Memory::KIND_FACT)
            ->latest('id')
            ->limit((int) config('rag.memory.recall_facts', 6))
            ->get();

        return $childFacts->concat($convoFacts)->all();
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
     * Extract durable facts from a completed turn and store them. Best-effort
     * and side-effect free on failure — meant to run AFTER the response is sent.
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

        $existing = Memory::where('device_id', $deviceId)->get();
        foreach ($existing as $e) {
            similar_text(mb_strtolower($e->content), mb_strtolower($content), $pct);
            if ($pct >= 82.0) {
                $e->forceFill([
                    'content' => $content,
                    'kind' => $kind,
                    'source_chat_id' => $chatId,
                    'last_used_at' => now(),
                ])->save();

                return;
            }
        }

        Memory::create([
            'device_id' => $deviceId,
            'worker_id' => $workerId,
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
