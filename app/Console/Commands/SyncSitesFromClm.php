<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('sites:sync-clm {--dry : Report without writing} {--prune : Delete local rows missing from CLM (schedule-days-free rows only)}')]
#[Description('Sync site locations from the EPI CLM outreach-site register (authoritative source, same server).')]
class SyncSitesFromClm extends Command
{
    /** Plausible Sindh bounding box — coordinates outside it are surveyor errors. */
    private const LAT_MIN = 23.5, LAT_MAX = 28.5, LNG_MIN = 66.0, LNG_MAX = 71.0;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        $rows = DB::connection('epi_clm')->table('outreach_sites')->get();
        if ($rows->isEmpty()) {
            $this->error('CLM outreach_sites returned no rows — check the epi_clm connection.');

            return self::FAILURE;
        }
        $this->info("CLM register: {$rows->count()} rows.");

        $norm = function (string $s): string {
            $s = preg_replace('/[\s\-]+/u', '', mb_strtolower(trim($s)));

            return (string) preg_replace('/(?<!\d)0+(\d)/', '$1', (string) $s);
        };
        $key = fn ($uc, $fix, $outreach): string => $norm((string) $uc).'|'.$norm((string) $fix).'|'.$norm((string) $outreach);

        // Index local rows by identity for upsert matching.
        $local = Site::all()->keyBy(fn (Site $s) => $key($s->union_council, $s->fix_site, $s->outreach_site));

        $created = $updated = $unchanged = $badCoords = 0;
        $seen = [];

        foreach ($rows as $row) {
            $k = $key($row->union_council, $row->fix_site, $row->outreach_site);
            $seen[$k] = true;

            // "lat,lng" string → validated floats (invalid → import without coords).
            $lat = $lng = null;
            if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', (string) $row->coordinates, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if ($lat < self::LAT_MIN || $lat > self::LAT_MAX || $lng < self::LNG_MIN || $lng > self::LNG_MAX) {
                    $this->warn("  ! out-of-region coords dropped: {$row->fix_site} / {$row->outreach_site} ({$row->coordinates})");
                    $lat = $lng = null;
                    $badCoords++;
                }
            } elseif (trim((string) $row->coordinates) !== '') {
                $this->warn("  ! unparseable coords: {$row->fix_site} / {$row->outreach_site} ('{$row->coordinates}')");
                $badCoords++;
            }

            $attrs = [
                'district' => trim((string) $row->district),
                'union_council' => trim((string) $row->union_council),
                'fix_site' => trim((string) $row->fix_site),
                'outreach_site' => trim((string) ($row->outreach_site ?? '')) ?: null,
                'latitude' => $lat,
                'longitude' => $lng,
            ];

            $existing = $local->get($k);
            if ($existing) {
                // Update identity/coords only — timing + BCG/MR days are OURS
                // (imported from the SHR UC schedule) and must survive the sync.
                $dirty = false;
                foreach ($attrs as $col => $val) {
                    if ($existing->{$col} != $val) {
                        $dirty = true;
                        break;
                    }
                }
                if ($dirty) {
                    if (! $dry) {
                        $existing->update($attrs);
                    }
                    $updated++;
                } else {
                    $unchanged++;
                }
            } else {
                if (! $dry) {
                    Site::create($attrs);
                }
                $created++;
            }
        }

        // Local rows not present in CLM: stale imports or schedule-created
        // placeholders. Rows carrying schedule days are kept unless the same
        // facility already exists via CLM rows.
        $localOnly = $local->filter(fn (Site $s, string $k): bool => ! isset($seen[$k]));
        foreach ($localOnly as $s) {
            $label = "{$s->fix_site} / ".($s->outreach_site ?: '—')." [{$s->union_council}]";
            if ($this->option('prune')) {
                if (! $dry) {
                    $s->delete();
                }
                $this->line("  - pruned: {$label}");
            } else {
                $this->line("  ? local-only (not in CLM): {$label}");
            }
        }

        if (! $dry) {
            Cache::forget('site_known_areas');
        }

        $this->info(($dry ? '[DRY RUN] ' : '')
            ."Sync: {$created} created, {$updated} updated, {$unchanged} unchanged, "
            .$localOnly->count().' local-only'.($this->option('prune') ? ' (pruned)' : '')
            .", {$badCoords} bad-coordinate rows.");

        return self::SUCCESS;
    }
}
