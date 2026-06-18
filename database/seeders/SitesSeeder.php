<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SitesSeeder extends Seeder
{
    public function run(): void
    {
        $sites = require database_path('data/sites.php');

        $rows = [];
        foreach ($sites as $s) {
            [$lat, $lng] = $this->parseCoordinates($s['coordinates'] ?? '');
            $rows[] = [
                'district' => trim((string) ($s['district'] ?? '')),
                'union_council' => trim((string) ($s['union_council'] ?? '')),
                'fix_site' => trim((string) ($s['fix_site'] ?? '')),
                'outreach_site' => trim((string) ($s['outreach_site'] ?? '')) ?: null,
                'latitude' => $lat,
                'longitude' => $lng,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Rebuild from scratch so re-seeding stays idempotent.
        DB::table('sites')->truncate();
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('sites')->insert($chunk);
        }
    }

    /**
     * "24.833590,67.226353" → [24.833590, 67.226353]. Returns [null, null] for
     * blank/invalid/out-of-range values.
     *
     * @return array{0: float|null, 1: float|null}
     */
    protected function parseCoordinates(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || ! str_contains($raw, ',')) {
            return [null, null];
        }

        [$lat, $lng] = array_map('trim', explode(',', $raw, 2));
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return [null, null];
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return [null, null];
        }

        return [$lat, $lng];
    }
}
