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
            $name = $s->outreach_site && $s->outreach_site !== 'Fixed Site'
                ? "{$s->fix_site} — {$s->outreach_site}"
                : $s->fix_site;
            $lines[] = "- {$name} (UC {$s->union_council}, {$s->district}) — {$km} km away — "
                ."coordinates {$s->latitude},{$s->longitude}";
        }

        return "USER'S NEAREST VACCINATION SITES (based on their current location):\n".implode("\n", $lines);
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
