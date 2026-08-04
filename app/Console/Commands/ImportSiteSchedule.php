<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sites:schedule-import {csv : CSV with District, UC, Fix Site, Coordinates, BCG day, MR day} {--dry : Report matches without writing}')]
#[Description('Import per-site BCG/MR session days from the SHR UC schedule sheet.')]
class ImportSiteSchedule extends Command
{
    public function handle(): int
    {
        $path = (string) $this->argument('csv');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $rows = array_map('str_getcsv', file($path));
        // Skip the header row (starts with "District").
        if (isset($rows[0][0]) && stripos((string) $rows[0][0], 'district') !== false) {
            array_shift($rows);
        }

        $norm = fn (string $s): string => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));

        $updated = $created = 0;
        $fuzzy = [];
        $unparsed = [];

        foreach ($rows as $i => $row) {
            $district = trim((string) ($row[0] ?? ''));
            $uc = trim((string) ($row[1] ?? ''));
            $name = trim((string) ($row[2] ?? ''), " .\t");
            $bcg = Site::normalizeDay($row[4] ?? null);
            $mr = Site::normalizeDay($row[5] ?? null);

            if ($name === '' || ($bcg === null && $mr === null)) {
                if ($name !== '') {
                    $unparsed[] = "row ".($i + 2).": {$name} (days unreadable: '".($row[4] ?? '')."'/'".($row[5] ?? '')."')";
                }

                continue;
            }

            // Candidates: sites in the same (normalized) UC.
            $ucNorm = preg_replace('/[\s\-]+/u', '', mb_strtolower($uc));
            $candidates = Site::query()
                ->get()
                ->filter(fn (Site $s): bool => preg_replace('/[\s\-]+/u', '', mb_strtolower((string) $s->union_council)) === $ucNorm);

            // Exact normalized name match first, then closest Levenshtein within
            // a conservative bound (handles sheet typos like "Hopsital").
            $target = $norm($name);
            $match = $candidates->first(fn (Site $s): bool => $norm((string) $s->fix_site) === $target);
            if (! $match) {
                $best = null;
                $bestDist = PHP_INT_MAX;
                foreach ($candidates as $s) {
                    $d = levenshtein($target, $norm((string) $s->fix_site));
                    if ($d < $bestDist) {
                        $bestDist = $d;
                        $best = $s;
                    }
                }
                if ($best && $bestDist <= max(3, (int) (mb_strlen($target) * 0.25))) {
                    $match = $best;
                    $fuzzy[] = "'{$name}' → '{$best->fix_site}' (edit distance {$bestDist})";
                }
            }

            if ($match) {
                if (! $dry) {
                    $match->update(['bcg_day' => $bcg, 'mr_day' => $mr]);
                }
                $updated++;
            } else {
                // Site listed on the schedule but missing from the register:
                // create it (no coordinates yet) so UC/area answers include it.
                if (! $dry) {
                    Site::create([
                        'district' => str_ireplace('district ', '', $district),
                        'union_council' => $uc,
                        'fix_site' => $name,
                        'bcg_day' => $bcg,
                        'mr_day' => $mr,
                    ]);
                }
                $created++;
                $this->line("  + created (no coords): {$name} [{$uc}]");
            }
        }

        foreach ($fuzzy as $f) {
            $this->line("  ~ fuzzy: {$f}");
        }
        foreach ($unparsed as $u) {
            $this->warn("  ! skipped: {$u}");
        }
        $this->info(($dry ? '[DRY RUN] ' : '')."Schedule import: {$updated} updated, {$created} created, ".count($fuzzy).' fuzzy-matched, '.count($unparsed).' skipped.');

        return self::SUCCESS;
    }
}
