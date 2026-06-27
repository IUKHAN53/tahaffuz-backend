<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Deterministic Pakistan EPI immunization schedule. Given a child's date of
 * birth and the vaccines already received, it computes exact due dates and
 * flags what is done / due / overdue — no LLM guessing for the one thing that
 * must be correct.
 */
class VaccineSchedule
{
    /** Scheduled antigens with their recommended age from birth. */
    public const SCHEDULE = [
        ['code' => 'BCG', 'age' => [0, 'weeks']],
        ['code' => 'OPV-0', 'age' => [0, 'weeks']],
        ['code' => 'Hep B', 'age' => [0, 'weeks']],
        ['code' => 'OPV-1', 'age' => [6, 'weeks']],
        ['code' => 'Penta-1', 'age' => [6, 'weeks']],
        ['code' => 'PCV-1', 'age' => [6, 'weeks']],
        ['code' => 'Rota-1', 'age' => [6, 'weeks']],
        ['code' => 'OPV-2', 'age' => [10, 'weeks']],
        ['code' => 'Penta-2', 'age' => [10, 'weeks']],
        ['code' => 'PCV-2', 'age' => [10, 'weeks']],
        ['code' => 'Rota-2', 'age' => [10, 'weeks']],
        ['code' => 'OPV-3', 'age' => [14, 'weeks']],
        ['code' => 'Penta-3', 'age' => [14, 'weeks']],
        ['code' => 'PCV-3', 'age' => [14, 'weeks']],
        ['code' => 'IPV-1', 'age' => [14, 'weeks']],
        ['code' => 'IPV-2', 'age' => [9, 'months']],
        ['code' => 'Typhoid', 'age' => [9, 'months']],
        ['code' => 'MR-1', 'age' => [9, 'months']],
        ['code' => 'MR-2', 'age' => [15, 'months']],
    ];

    /**
     * Per-antigen status for a child.
     *
     * @param  array<int, string>  $givenNames  vaccine names already received
     * @return array<int, array{code:string, due_date:?string, status:string}>
     *         status ∈ done | overdue | due_soon | upcoming | unknown_dob
     */
    public function build(?Carbon $dob, array $givenNames, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $given = array_map(fn ($n) => $this->normalize($n), $givenNames);

        $rows = [];
        foreach (self::SCHEDULE as $v) {
            $isGiven = in_array($this->normalize($v['code']), $given, true);
            $due = $dob ? $this->addAge($dob->copy(), $v['age']) : null;

            if ($isGiven) {
                $status = 'done';
            } elseif ($due === null) {
                $status = 'unknown_dob';
            } elseif ($due->lt($today)) {
                $status = 'overdue';
            } elseif ($due->diffInDays($today) <= 14) {
                $status = 'due_soon';
            } else {
                $status = 'upcoming';
            }

            $rows[] = [
                'code' => $v['code'],
                'due_date' => $due?->toDateString(),
                'status' => $status,
            ];
        }

        return $rows;
    }

    /**
     * Compact summary for prompts / API: overdue + the single next-due antigen.
     *
     * @return array{overdue: array<int,string>, next: ?array{code:string,due_date:?string}, has_dob: bool}
     */
    public function summary(?Carbon $dob, array $givenNames, ?Carbon $today = null): array
    {
        $rows = $this->build($dob, $givenNames, $today);
        $overdue = array_values(array_map(
            fn ($r) => $r['code'].($r['due_date'] ? " (due {$r['due_date']})" : ''),
            array_filter($rows, fn ($r) => $r['status'] === 'overdue'),
        ));
        $next = null;
        foreach ($rows as $r) {
            if (in_array($r['status'], ['due_soon', 'upcoming'], true)) {
                $next = ['code' => $r['code'], 'due_date' => $r['due_date']];
                break;
            }
        }

        return ['overdue' => $overdue, 'next' => $next, 'has_dob' => $dob !== null];
    }

    /** Parse a handwritten card DOB like "29/4/26", "29-04-2026", "2026-04-29". */
    public function parseDob(?string $s): ?Carbon
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        foreach (['d/m/Y', 'd/m/y', 'd-m-Y', 'd-m-y', 'Y-m-d', 'd.m.Y', 'd.m.y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $s);
                if ($d && $d->year >= 2000 && $d->year <= (int) date('Y') + 1) {
                    return $d->startOfDay();
                }
            } catch (\Throwable) {
                // try next format
            }
        }

        return null;
    }

    /** Vaccine names from a card's vaccines array. */
    public function givenFromCard(mixed $vaccines): array
    {
        return collect(is_array($vaccines) ? $vaccines : [])
            ->map(fn ($v) => is_array($v) ? trim((string) ($v['name'] ?? '')) : '')
            ->filter()
            ->values()
            ->all();
    }

    protected function addAge(Carbon $d, array $age): Carbon
    {
        [$n, $unit] = $age;

        return $unit === 'months' ? $d->addMonths($n) : $d->addWeeks($n);
    }

    protected function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($name))) ?? '';
    }
}
