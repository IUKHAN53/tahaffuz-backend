<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;

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
     * Vaccination sites in a union council, as nearest()-shaped hits (no
     * distance), for the registered-worker fallback. Case/spacing-insensitive
     * UC match, fixed sites first, only sites that have coordinates.
     *
     * @return array<int, array{site: Site}>
     */
    public function ucHits(string $unionCouncil, int $limit = 3): array
    {
        $norm = fn (string $s): string => preg_replace('/[\s\-]+/u', '', mb_strtolower(trim($s)));
        $target = $norm($unionCouncil);

        return Site::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->filter(fn (Site $s): bool => $norm((string) $s->union_council) === $target)
            ->sortBy(fn (Site $s): int => $this->siteName($s) === $s->fix_site ? 0 : 1)
            ->take($limit)
            ->map(fn (Site $s): array => ['site' => $s])
            ->values()
            ->all();
    }

    /**
     * Distinct area names (union councils, towns, districts) that have at least
     * one geolocated site — the candidate list handed to the LLM area matcher.
     * Cached for an hour; the site table changes rarely.
     *
     * @return array<int, string>
     */
    public function knownAreas(): array
    {
        return Cache::remember('site_known_areas', 3600, function (): array {
            return Site::query()
                ->whereNotNull('latitude')
                ->get(['union_council', 'town', 'district'])
                ->flatMap(fn (Site $s): array => [$s->union_council, $s->town, $s->district])
                ->map(fn ($a): string => trim((string) $a))
                ->filter()
                ->unique()
                ->values()
                ->all();
        });
    }

    /**
     * Sites in a named area — matched against union council, town OR district
     * (case/spacing-insensitive). Used when the user NAMES a place but we have
     * no GPS fix, e.g. "I live in Chishti Nagar". Fixed sites first.
     *
     * @return array<int, array{site: Site}>
     */
    public function sitesInArea(string $area, int $limit = 3): array
    {
        $norm = fn (string $s): string => preg_replace('/[\s\-]+/u', '', mb_strtolower(trim($s)));
        $target = $norm($area);
        if ($target === '') {
            return [];
        }

        return Site::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->filter(fn (Site $s): bool => $norm((string) $s->union_council) === $target
                || $norm((string) $s->town) === $target
                || $norm((string) $s->district) === $target)
            ->sortBy(fn (Site $s): int => $this->siteName($s) === $s->fix_site ? 0 : 1)
            ->take($limit)
            ->map(fn (Site $s): array => ['site' => $s])
            ->values()
            ->all();
    }

    /**
     * LLM-facing context describing a set of site hits (name, area, distance).
     * No coordinates or links — those are appended deterministically afterward.
     *
     * @param  array<int, array{site: Site, distance_km?: float}>  $hits
     */
    public function contextFromHits(array $hits): string
    {
        $lines = [];
        foreach ($hits as $h) {
            $s = $h['site'];
            $dist = isset($h['distance_km']) ? ' — '.round($h['distance_km'], 1).' km away' : '';
            $lines[] = "- {$this->siteName($s)} (UC {$s->union_council}, {$s->district}){$dist}";
        }

        return "AVAILABLE VACCINATION SITES FOR THIS USER:\n".implode("\n", $lines);
    }

    /**
     * The user-facing site answer, built deterministically from the data — no
     * LLM call. The intro line is localised; site names/areas come straight from
     * the records so they stay consistent with the Google Maps pins (appended
     * separately by mapsBlock). Instant, and it can never apologize.
     *
     * @param  array<int, array{site: Site, distance_km?: float}>  $hits
     */
    public function answerText(array $hits, string $language): string
    {
        $intro = match ($language) {
            'ur' => 'آپ درج ذیل مراکز پر ٹیکا لگوا سکتے ہیں:',
            'fa' => 'می‌توانید در مراکز زیر واکسن بزنید:',
            'ps' => 'تاسو کولی شئ په لاندې مرکزونو کې واکسین ولګوئ:',
            'sd' => 'توهان هيٺين هنڌن تي ويڪسين لڳائي سگهو ٿا:',
            default => 'You can get vaccinated at the following sites:',
        };

        $lines = [];
        foreach ($hits as $h) {
            $s = $h['site'];
            $area = trim((string) $s->union_council);
            $dist = isset($h['distance_km']) ? ' — '.round($h['distance_km'], 1).' km' : '';
            $lines[] = '• '.$this->siteName($s).($area !== '' ? " ({$area})" : '').$dist;
        }

        return $intro."\n".implode("\n", $lines);
    }

    /**
     * Structured site payload for the app's tappable site cards: name, area,
     * distance, maps link, and opening hours. `timing` is the compact display
     * label ("Mon-Sat 9AM-2PM"); the structured days/open/close fields ride
     * along so future app screens can render schedules without re-parsing.
     * Every site runs the standard EPI hours unless specifically overridden
     * in the admin, so `timing` is always present.
     *
     * @param  array<int, array{site: Site, distance_km?: float}>  $hits
     * @return array<int, array<string, mixed>>
     */
    public function sitesPayload(array $hits): array
    {
        return array_map(function (array $h): array {
            $s = $h['site'];
            $area = trim((string) $s->union_council);

            return [
                'name' => $this->siteName($s),
                'area' => $area !== '' ? $area : null,
                'distance_km' => isset($h['distance_km']) ? round((float) $h['distance_km'], 1) : null,
                'maps_url' => $this->mapsUrl($s),
                'timing' => $s->timingLabel(),
                'timing_days' => $s->timingDays(),
                'open_time' => $s->openTime(),
                'close_time' => $s->closeTime(),
                'break_start' => $s->breakStart(),
                'break_end' => $s->breakEnd(),
            ];
        }, $hits);
    }

    /** Google Maps pin URL for a site, or null when it has no coordinates. */
    public function mapsUrl(Site $s): ?string
    {
        if ($s->latitude === null || $s->longitude === null) {
            return null;
        }

        return "https://www.google.com/maps/search/?api=1&query={$s->latitude},{$s->longitude}";
    }

    /**
     * A markdown list of the sites with tappable Google Maps pins, appended to a
     * site answer so the links are always correct (never LLM-generated/mangled).
     *
     * @param  array<int, array{site: Site, distance_km?: float}>  $hits
     */
    public function mapsBlock(array $hits): string
    {
        $lines = [];
        foreach ($hits as $h) {
            $s = $h['site'];
            $url = $this->mapsUrl($s);
            if ($url === null) {
                continue;
            }
            $dist = isset($h['distance_km']) ? ' — '.round($h['distance_km'], 1).' km' : '';
            $lines[] = "📍 [{$this->siteName($s)}]({$url}){$dist}";
        }

        return implode("\n", $lines);
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
