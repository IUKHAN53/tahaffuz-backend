<?php

namespace App\Services;

use App\Models\Site;

class SiteLocator
{
    /**
     * Nearest vaccination sites to a coordinate, by great-circle distance.
     * The site table is small (~hundreds of rows), so we load the ones with
     * coordinates and rank them in PHP — no DB trig functions required.
     *
     * @return array<int, array{site: Site, distance_km: float}>
     */
    public function nearest(float $lat, float $lng, int $limit = 3, ?string $unionCouncil = null): array
    {
        $query = Site::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if ($unionCouncil) {
            $query->where('union_council', $unionCouncil);
        }

        $ranked = $query->get()
            ->map(fn (Site $s) => [
                'site' => $s,
                'distance_km' => $this->haversineKm($lat, $lng, (float) $s->latitude, (float) $s->longitude),
            ])
            ->sortBy('distance_km')
            ->take($limit)
            ->values()
            ->all();

        return $ranked;
    }

    /**
     * Compact, model-free context block describing the user's nearest sites,
     * for injection into the chat prompt.
     */
    public function nearestContext(float $lat, float $lng, int $limit = 3): string
    {
        $hits = $this->nearest($lat, $lng, $limit);
        if (empty($hits)) {
            return '';
        }

        $lines = [];
        foreach ($hits as $h) {
            $s = $h['site'];
            $km = round($h['distance_km'], 1);
            $name = $this->siteName($s);
            $lines[] = "- {$name} (UC {$s->union_council}, {$s->district}) — {$km} km away — "
                ."coordinates {$s->latitude},{$s->longitude}";
        }

        return "USER'S NEAREST VACCINATION SITES (based on their current location):\n".implode("\n", $lines);
    }

    /**
     * Context block listing the vaccination sites in a given union council —
     * used when we don't have the worker's GPS but know their registered UC.
     */
    public function ucContext(string $unionCouncil, int $limit = 8): string
    {
        // Match on a normalized union-council label (case- and spacing-
        // insensitive) so a registered worker still gets their sites even when
        // the stored label differs cosmetically from the site rows, e.g.
        // "Mangopir-8" vs "Mangopir - 8".
        $norm = fn (string $s): string => preg_replace('/[\s\-]+/u', '', mb_strtolower(trim($s)));
        $target = $norm($unionCouncil);

        $sites = Site::query()
            ->whereNotNull('union_council')
            ->get()
            ->filter(fn (Site $s): bool => $norm((string) $s->union_council) === $target)
            ->sortBy(fn (Site $s): int => $s->outreach_site && $s->outreach_site !== 'Fixed Site' ? 1 : 0)
            ->take($limit)
            ->values();

        if ($sites->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($sites as $s) {
            $name = $this->siteName($s);
            $coords = $s->latitude !== null ? " — coordinates {$s->latitude},{$s->longitude}" : '';
            $lines[] = "- {$name} (UC {$s->union_council}, {$s->district}){$coords}";
        }

        return "VACCINATION SITES IN THE USER'S UNION COUNCIL ({$unionCouncil}):\n".implode("\n", $lines);
    }

    /**
     * Human-readable site name. Appends the outreach-site label only when it's
     * a real one — "Fixed Site", blank, or junk seed values like "nan"/"null"
     * are dropped so answers don't read "RHC Manghopir — nan".
     */
    protected function siteName(Site $s): string
    {
        $outreach = trim((string) $s->outreach_site);
        $hasOutreach = $outreach !== ''
            && ! in_array(mb_strtolower($outreach), ['fixed site', 'nan', 'null', 'n/a', '-'], true);

        return $hasOutreach ? "{$s->fix_site} — {$outreach}" : (string) $s->fix_site;
    }

    protected function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
