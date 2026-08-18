<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('sites:load-official {csv : district,union_council,fix_site,latitude,longitude,bcg_day,mr_day} {--dry : Preview without replacing}')]
#[Description('REPLACE the sites table with the official fix-site list (client mandate: only these locations are offered).')]
class LoadOfficialSites extends Command
{
    public function handle(): int
    {
        $path = (string) $this->argument('csv');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $rows = array_map('str_getcsv', file($path));
        $header = array_map(fn ($h) => trim((string) $h), array_shift($rows) ?: []);
        $expected = ['district', 'union_council', 'fix_site', 'latitude', 'longitude', 'bcg_day', 'mr_day'];
        if ($header !== $expected) {
            $this->error('Unexpected header: '.implode(',', $header));

            return self::FAILURE;
        }

        $sites = [];
        foreach ($rows as $i => $row) {
            $r = array_combine($expected, array_pad($row, 7, ''));
            $name = trim((string) $r['fix_site']);
            if ($name === '') {
                continue;
            }
            $lat = is_numeric($r['latitude']) ? (float) $r['latitude'] : null;
            $lng = is_numeric($r['longitude']) ? (float) $r['longitude'] : null;
            if ($lat !== null && ($lat < 23.5 || $lat > 28.5 || $lng < 66 || $lng > 71)) {
                $this->warn('  ! out-of-region coords dropped: '.$name);
                $lat = $lng = null;
            }
            $sites[] = [
                'district' => trim((string) $r['district']),
                'union_council' => trim((string) $r['union_council']),
                'fix_site' => $name,
                'outreach_site' => null,
                'latitude' => $lat,
                'longitude' => $lng,
                'bcg_day' => Site::normalizeDay($r['bcg_day']),
                'mr_day' => Site::normalizeDay($r['mr_day']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $withCoords = count(array_filter($sites, fn ($s) => $s['latitude'] !== null));
        $this->info('Official list: '.count($sites)." sites, {$withCoords} with coordinates.");
        foreach ($sites as $s) {
            if ($s['latitude'] === null) {
                $this->warn("  ! no coordinates: {$s['fix_site']} [{$s['union_council']}] — will appear in UC/area answers only");
            }
        }

        if ($this->option('dry')) {
            $this->info('[DRY RUN] sites table untouched (currently '.Site::count().' rows).');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($sites) {
            Site::query()->delete();
            foreach ($sites as $s) {
                Site::create($s);
            }
        });
        Cache::forget('site_known_areas');

        $this->info('REPLACED: sites table now holds '.Site::count().' official fix sites.');
        $this->warn('Note: sites:sync-clm is SUPERSEDED by this list — running it would re-add the outreach register.');

        return self::SUCCESS;
    }
}
