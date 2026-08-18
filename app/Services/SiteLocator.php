<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;

class SiteLocator
{
    /**
     * Localized weekday names for the deterministic site answers. Pashto and
     * Farsi share the Persian weekday names in common use; Sindhi has its own.
     *
     * @var array<string, array<string, string>>
     */
    public const DAY_NAMES = [
        'en' => ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'],
        'ur' => ['mon' => 'پیر', 'tue' => 'منگل', 'wed' => 'بدھ', 'thu' => 'جمعرات', 'fri' => 'جمعہ', 'sat' => 'ہفتہ', 'sun' => 'اتوار'],
        'fa' => ['mon' => 'دوشنبه', 'tue' => 'سه‌شنبه', 'wed' => 'چهارشنبه', 'thu' => 'پنجشنبه', 'fri' => 'جمعه', 'sat' => 'شنبه', 'sun' => 'یکشنبه'],
        'ps' => ['mon' => 'دوشنبه', 'tue' => 'سه‌شنبه', 'wed' => 'چهارشنبه', 'thu' => 'پنجشنبه', 'fri' => 'جمعه', 'sat' => 'شنبه', 'sun' => 'یکشنبه'],
        'sd' => ['mon' => 'سومر', 'tue' => 'اڱارو', 'wed' => 'اربع', 'thu' => 'خميس', 'fri' => 'جمعو', 'sat' => 'ڇنڇر', 'sun' => 'آچر'],
    ];

    /** Localized weekday name for a mon..sun code (falls back to English). */
    public function dayName(?string $code, string $language): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::DAY_NAMES[$language][$code] ?? self::DAY_NAMES['en'][$code] ?? null;
    }

    /** Localized "site is open …" phrase, e.g. "پیر–ہفتہ 9AM-2PM". */
    protected function timingPhrase(Site $s, string $language): string
    {
        $names = self::DAY_NAMES[$language] ?? self::DAY_NAMES['en'];
        $days = $s->timingDays();
        $first = $names[$days[0]] ?? '';
        $last = $names[$days[count($days) - 1]] ?? '';
        $range = count($days) >= 2 ? "{$first}–{$last}" : $first;

        return trim($range.' '.Site::formatTime($s->openTime()).'-'.Site::formatTime($s->closeTime()));
    }

    /**
     * Detect whether the question is about a SPECIFIC session-day vaccine.
     * BCG and MR vials are opened on fixed weekdays; everything else runs on
     * the site's normal daily hours.
     */
    public function vaccineFocus(string $text): ?string
    {
        if (preg_match('/\bbcg\b|بی\s*سی\s*جی|بي\s*سي\s*جي/iu', $text)) {
            return 'bcg';
        }
        if (preg_match('/\bmr\b|measles|خسرہ|خسره|سرخک|شرۍ/iu', $text)) {
            return 'mr';
        }

        return null;
    }
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

        // One entry per FACILITY: a fix site has many outreach rows (often
        // sharing coordinates), and without collapsing them the "3 nearest
        // sites" were frequently the same hospital three times.
        return $query->get()
            ->map(fn (Site $s) => [
                'site' => $s,
                'distance_km' => $this->haversineKm($lat, $lng, (float) $s->latitude, (float) $s->longitude),
            ])
            ->sortBy('distance_km')
            ->unique(fn (array $h): string => $this->facilityKey($h['site']))
            ->take($limit)
            ->values()
            ->all();
    }

    /** Normalized facility identity: fix site + UC (spacing/dash/zero-insensitive). */
    protected function facilityKey(Site $s): string
    {
        return $this->normalizeArea((string) $s->fix_site).'|'.$this->normalizeArea((string) $s->union_council);
    }

    /**
     * Area/name normalizer for matching: lowercase, strip spaces and dashes,
     * and drop leading zeros in numbers so "Islamia Colony-09" and
     * "Islamia Colony-9" (both present in the register) compare equal.
     */
    protected function normalizeArea(string $s): string
    {
        $s = preg_replace('/[\s\-]+/u', '', mb_strtolower(trim($s)));

        return (string) preg_replace('/(?<!\d)0+(\d)/', '$1', (string) $s);
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
        // Match on a normalized union-council label (case/spacing/zero-
        // insensitive) so a registered worker still gets their sites even when
        // the stored label differs cosmetically, e.g. "Mangopir-8" vs
        // "Mangopir - 8" or "Islamia Colony-09" vs "-9".
        $target = $this->normalizeArea($unionCouncil);

        $sites = Site::query()
            ->whereNotNull('union_council')
            ->get()
            ->filter(fn (Site $s): bool => $this->normalizeArea((string) $s->union_council) === $target)
            ->sortBy(fn (Site $s): int => $s->outreach_site && $s->outreach_site !== 'Fixed Site' ? 1 : 0)
            ->unique(fn (Site $s): string => $this->facilityKey($s))
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
        $target = $this->normalizeArea($unionCouncil);

        return Site::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->filter(fn (Site $s): bool => $this->normalizeArea((string) $s->union_council) === $target)
            ->sortBy(fn (Site $s): int => $this->siteName($s) === $s->fix_site ? 0 : 1)
            ->unique(fn (Site $s): string => $this->facilityKey($s))
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
        $target = $this->normalizeArea($area);
        if ($target === '') {
            return [];
        }

        return Site::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->filter(fn (Site $s): bool => $this->normalizeArea((string) $s->union_council) === $target
                || $this->normalizeArea((string) $s->town) === $target
                || $this->normalizeArea((string) $s->district) === $target)
            ->sortBy(fn (Site $s): int => $this->siteName($s) === $s->fix_site ? 0 : 1)
            ->unique(fn (Site $s): string => $this->facilityKey($s))
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
    public function answerText(array $hits, string $language, ?string $vaccine = null): string
    {
        // Sites run their normal hours daily for ALL routine vaccines; only
        // BCG and MR happen on special weekdays (multi-dose vials). So the
        // general answer shows "open <days/hours>" plus "BCG only <day>", and
        // a BCG/MR-specific question leads with that vaccine's day.
        $only = match ($language) {
            'ur' => 'صرف', 'fa' => 'فقط', 'ps' => 'یوازې', 'sd' => 'صرف', default => 'only on',
        };
        $open = match ($language) {
            'ur' => 'اوقات', 'fa' => 'ساعات', 'ps' => 'وخت', 'sd' => 'اوقات', default => 'open',
        };

        if ($vaccine !== null) {
            $v = strtoupper($vaccine);
            $intro = match ($language) {
                'ur' => "{$v} کے لیے آپ درج ذیل مراکز پر ٹیکہ لگوا سکتے ہیں:",
                'fa' => "برای {$v} می‌توانید در مراکز زیر واکسن بزنید:",
                'ps' => "د {$v} لپاره تاسو په لاندې مرکزونو کې واکسین لګولی شئ:",
                'sd' => "{$v} لاءِ توهان هيٺين هنڌن تي ويڪسين لڳائي سگهو ٿا:",
                default => "For {$v}, you can get vaccinated at the following sites:",
            };
        } else {
            // When even the nearest hit is far, be honest about it — testers
            // standing outside the covered UCs read "you can get vaccinated at
            // [11 km away]" as a wrong answer.
            $first = $hits[0] ?? null;
            $far = $first !== null && isset($first['distance_km']) && (float) $first['distance_km'] > 5.0;

            $intro = $far
                ? match ($language) {
                    'ur' => 'آپ کے بالکل قریب کوئی رجسٹرڈ مرکز نہیں ملا۔ سب سے قریبی مراکز یہ ہیں:',
                    'fa' => 'مرکز ثبت‌شده‌ای خیلی نزدیک شما پیدا نشد. نزدیک‌ترین مراکز این‌ها هستند:',
                    'ps' => 'ستاسو ډېر نږدې کوم راجسټر شوی مرکز ونه موندل شو. تر ټولو نږدې دا دي:',
                    'sd' => 'توهان جي بلڪل ويجهو ڪو رجسٽرڊ مرڪز نه مليو. سڀ کان ويجها مرڪز هي آهن:',
                    default => 'No registered site is very close to your location. The nearest ones are:',
                }
                : match ($language) {
                    'ur' => 'آپ درج ذیل مراکز پر ٹیکا لگوا سکتے ہیں:',
                    'fa' => 'می‌توانید در مراکز زیر واکسن بزنید:',
                    'ps' => 'تاسو کولی شئ په لاندې مرکزونو کې واکسین ولګوئ:',
                    'sd' => 'توهان هيٺين هنڌن تي ويڪسين لڳائي سگهو ٿا:',
                    default => 'You can get vaccinated at the following sites:',
                };
        }

        // For a vaccine-specific ask, sites that HAVE that vaccine's day first.
        if ($vaccine !== null) {
            usort($hits, function (array $a, array $b) use ($vaccine): int {
                $dayOf = fn (Site $s): ?string => $vaccine === 'bcg' ? $s->bcgDay() : $s->mrDay();

                return ($dayOf($a['site']) === null ? 1 : 0) <=> ($dayOf($b['site']) === null ? 1 : 0);
            });
        }

        $lines = [];
        foreach ($hits as $h) {
            $s = $h['site'];
            $area = trim((string) $s->union_council);
            $dist = isset($h['distance_km']) ? ' — '.round($h['distance_km'], 1).' km' : '';
            $line = '• '.$this->siteName($s).($area !== '' ? " ({$area})" : '').$dist;

            $timing = $this->timingPhrase($s, $language);

            if ($vaccine !== null) {
                // Lead with the asked vaccine's day: "BCG صرف پیر (اوقات 9AM-2PM)".
                $v = strtoupper($vaccine);
                $day = $this->dayName($vaccine === 'bcg' ? $s->bcgDay() : $s->mrDay(), $language);
                $line .= $day !== null
                    ? " — {$v} {$only} {$day} ({$open}: ".Site::formatTime($s->openTime()).'-'.Site::formatTime($s->closeTime()).')'
                    : " — {$open}: {$timing}";
            } else {
                // General ask: normal daily hours for all vaccines, then the
                // special BCG/MR days as exceptions.
                $line .= " — {$open}: {$timing}";
                $days = [];
                if ($bcg = $this->dayName($s->bcgDay(), $language)) {
                    $days[] = "BCG {$only} {$bcg}";
                }
                if ($mr = $this->dayName($s->mrDay(), $language)) {
                    $days[] = "MR {$only} {$mr}";
                }
                if (! empty($days)) {
                    $line .= ' — '.implode(' · ', $days);
                }
            }

            $lines[] = $line;
        }

        return $intro."\n".implode("\n", $lines);
    }

    /**
     * The SPOKEN version of a site answer — full natural sentences with no
     * bullets, brackets, dashes, or links, so the TTS voice reads it like a
     * person would ("the centre is open Monday to Saturday, from 9 in the
     * morning until 2 in the afternoon; the BCG vaccine is given on Wednesday").
     * Same data as answerText(); different rendering.
     *
     * @param  array<int, array{site: Site, distance_km?: float}>  $hits
     */
    public function speechAnswer(array $hits, string $language, ?string $vaccine = null): string
    {
        if (empty($hits)) {
            return '';
        }

        // Vaccine-specific asks lead with sites that HAVE that vaccine's day.
        if ($vaccine !== null) {
            usort($hits, function (array $a, array $b) use ($vaccine): int {
                $dayOf = fn (Site $s): ?string => $vaccine === 'bcg' ? $s->bcgDay() : $s->mrDay();

                return ($dayOf($a['site']) === null ? 1 : 0) <=> ($dayOf($b['site']) === null ? 1 : 0);
            });
        }

        $first = $hits[0];
        $far = isset($first['distance_km']) && (float) $first['distance_km'] > 5.0;
        $vName = $vaccine !== null ? $this->spokenVaccineName($vaccine, $language) : null;

        $intro = match (true) {
            $vaccine !== null => match ($language) {
                'ur' => "{$vName} کے لیے آپ یہ مراکز استعمال کر سکتے ہیں۔",
                'fa' => "برای {$vName} می‌توانید به این مراکز مراجعه کنید.",
                'ps' => "د {$vName} لپاره تاسو دې مرکزونو ته ورتلی شئ.",
                'sd' => "{$vName} لاءِ توهان هنن مرڪزن تي وڃي سگهو ٿا.",
                default => "For {$vName}, you can go to these centres.",
            },
            $far => match ($language) {
                'ur' => 'آپ کے بالکل قریب کوئی رجسٹرڈ مرکز نہیں ملا۔ سب سے قریبی مراکز یہ ہیں۔',
                'fa' => 'مرکز ثبت‌شده‌ای خیلی نزدیک شما پیدا نشد. نزدیک‌ترین مراکز این‌ها هستند.',
                'ps' => 'ستاسو ډېر نږدې کوم راجسټر شوی مرکز ونه موندل شو. تر ټولو نږدې دا دي.',
                'sd' => 'توهان جي بلڪل ويجهو ڪو رجسٽرڊ مرڪز نه مليو. سڀ کان ويجها مرڪز هي آهن.',
                default => 'No registered centre is very close to you. The nearest ones are these.',
            },
            default => match ($language) {
                'ur' => 'آپ ان مراکز پر ٹیکا لگوا سکتے ہیں۔',
                'fa' => 'می‌توانید در این مراکز واکسن بزنید.',
                'ps' => 'تاسو په دې مرکزونو کې واکسین لګولی شئ.',
                'sd' => 'توهان هنن مرڪزن تي ويڪسين لڳائي سگهو ٿا.',
                default => 'You can get vaccinated at these centres.',
            },
        };

        $parts = [$intro];
        foreach ($hits as $h) {
            $parts[] = $this->spokenSite($h, $language, $vaccine, $vName);
        }

        return implode(' ', array_filter($parts));
    }

    /** One site as flowing spoken sentences: name, distance, hours, vaccine days. */
    protected function spokenSite(array $h, string $language, ?string $vaccine, ?string $vName): string
    {
        $s = $h['site'];
        $name = $this->siteName($s);
        $sentences = [];

        // "Baber Hospital, about 2 kilometres from you."
        $sentences[] = isset($h['distance_km'])
            ? $this->spokenDistance($name, (float) $h['distance_km'], $language)
            : match ($language) {
                'ur' => "{$name}۔",
                'fa', 'ps', 'sd' => "{$name}.",
                default => "{$name}.",
            };

        // "The centre is open Monday to Saturday, from 9 in the morning to 2 in
        // the afternoon." (+ break sentence when the site has one)
        $names = self::DAY_NAMES[$language] ?? self::DAY_NAMES['en'];
        $days = $s->timingDays();
        $firstDay = $names[$days[0]] ?? '';
        $lastDay = $names[$days[count($days) - 1]] ?? '';
        $open = $this->spokenTime($s->openTime(), $language);
        $close = $this->spokenTime($s->closeTime(), $language);
        $sentences[] = count($days) >= 2
            ? match ($language) {
                'ur' => "یہ مرکز {$firstDay} سے {$lastDay} تک، {$open} سے {$close} تک کھلا رہتا ہے۔",
                'fa' => "این مرکز از {$firstDay} تا {$lastDay}، از {$open} تا {$close} باز است.",
                'ps' => "دا مرکز له {$firstDay} تر {$lastDay}، له {$open} څخه تر {$close} پورې خلاص وي.",
                'sd' => "هي مرڪز {$firstDay} کان {$lastDay} تائين، {$open} کان {$close} تائين کليل هوندو آهي.",
                default => "The centre is open {$firstDay} to {$lastDay}, from {$open} to {$close}.",
            }
            : match ($language) {
                'ur' => "یہ مرکز صرف {$firstDay} کو، {$open} سے {$close} تک کھلا رہتا ہے۔",
                'fa' => "این مرکز فقط {$firstDay}، از {$open} تا {$close} باز است.",
                'ps' => "دا مرکز یوازې {$firstDay}، له {$open} څخه تر {$close} پورې خلاص وي.",
                'sd' => "هي مرڪز صرف {$firstDay} تي، {$open} کان {$close} تائين کليل هوندو آهي.",
                default => "The centre is open only on {$firstDay}, from {$open} to {$close}.",
            };

        if ($s->hasBreak()) {
            $bs = $this->spokenTime((string) $s->breakStart(), $language);
            $be = $this->spokenTime((string) $s->breakEnd(), $language);
            $sentences[] = match ($language) {
                'ur' => "درمیان میں {$bs} سے {$be} تک وقفہ ہوتا ہے۔",
                'fa' => "بین {$bs} تا {$be} استراحت است.",
                'ps' => "د {$bs} او {$be} تر منځ وقفه وي.",
                'sd' => "وچ ۾ {$bs} کان {$be} تائين وقفو ٿيندو آهي.",
                default => "There is a break from {$bs} to {$be}.",
            };
        }

        // Vaccine days. A BCG/MR-specific ask gets just that vaccine's day; a
        // general ask mentions both special days when the site has them.
        if ($vaccine !== null) {
            $day = $this->dayName($vaccine === 'bcg' ? $s->bcgDay() : $s->mrDay(), $language);
            if ($day !== null) {
                $sentences[] = match ($language) {
                    'ur' => "{$vName} کا ٹیکہ یہاں {$day} کے دن لگتا ہے۔",
                    'fa' => "واکسن {$vName} اینجا روز {$day} زده می‌شود.",
                    'ps' => "د {$vName} واکسین دلته د {$day} په ورځ لګول کېږي.",
                    'sd' => "{$vName} جي ويڪسين هتي {$day} جي ڏينهن لڳندي آهي.",
                    default => "The {$vName} vaccine is given here on {$day}.",
                };
            }
        } else {
            $bcg = $this->dayName($s->bcgDay(), $language);
            $mr = $this->dayName($s->mrDay(), $language);
            $bcgName = $this->spokenVaccineName('bcg', $language);
            $mrName = $this->spokenVaccineName('mr', $language);
            if ($bcg !== null && $mr !== null) {
                $sentences[] = match ($language) {
                    'ur' => "{$bcgName} کا ٹیکہ صرف {$bcg} کو، اور {$mrName} کا ٹیکہ صرف {$mr} کو لگتا ہے۔",
                    'fa' => "واکسن {$bcgName} فقط {$bcg} و واکسن {$mrName} فقط {$mr} زده می‌شود.",
                    'ps' => "د {$bcgName} واکسین یوازې په {$bcg} او د {$mrName} واکسین یوازې په {$mr} لګول کېږي.",
                    'sd' => "{$bcgName} جي ويڪسين صرف {$bcg} تي، ۽ {$mrName} جي ويڪسين صرف {$mr} تي لڳندي آهي.",
                    default => "The {$bcgName} vaccine is given only on {$bcg}, and the {$mrName} vaccine only on {$mr}.",
                };
            } elseif ($bcg !== null || $mr !== null) {
                $n = $bcg !== null ? $bcgName : $mrName;
                $d = $bcg ?? $mr;
                $sentences[] = match ($language) {
                    'ur' => "{$n} کا ٹیکہ یہاں صرف {$d} کو لگتا ہے۔",
                    'fa' => "واکسن {$n} اینجا فقط {$d} زده می‌شود.",
                    'ps' => "د {$n} واکسین دلته یوازې په {$d} لګول کېږي.",
                    'sd' => "{$n} جي ويڪسين هتي صرف {$d} تي لڳندي آهي.",
                    default => "The {$n} vaccine is given here only on {$d}.",
                };
            }
        }

        return implode(' ', $sentences);
    }

    /** "Baber Hospital, about 2 kilometres from you." — spoken, no digits soup. */
    protected function spokenDistance(string $name, float $km, string $language): string
    {
        if ($km < 0.3) {
            return match ($language) {
                'ur' => "{$name}، جو آپ کے بالکل قریب ہے۔",
                'fa' => "{$name}، که خیلی نزدیک شماست.",
                'ps' => "{$name}، چې ستاسو ډېر نږدې دی.",
                'sd' => "{$name}، جيڪو توهان جي بلڪل ويجهو آهي.",
                default => "{$name}, which is right next to you.",
            };
        }

        // Whole kilometres read far better than "2 point 2 kilometres".
        $d = $km < 1 ? null : (string) max(1, (int) round($km));

        return match ($language) {
            'ur' => $d === null ? "{$name}، جو ایک کلومیٹر سے کم فاصلے پر ہے۔" : "{$name}، جو تقریباً {$d} کلومیٹر کے فاصلے پر ہے۔",
            'fa' => $d === null ? "{$name}، کمتر از یک کیلومتر فاصله دارد." : "{$name}، حدود {$d} کیلومتر فاصله دارد.",
            'ps' => $d === null ? "{$name}، چې له یو کیلومتره کم لرې دی." : "{$name}، چې شاوخوا {$d} کیلومتره لرې دی.",
            'sd' => $d === null ? "{$name}، جيڪو هڪ ڪلوميٽر کان گهٽ پنڌ تي آهي." : "{$name}، جيڪو لڳ ڀڳ {$d} ڪلوميٽر پري آهي.",
            default => $d === null ? "{$name}, less than a kilometre away." : "{$name}, about {$d} kilometres away.",
        };
    }

    /** "09:00" → "صبح 9 بجے" / "9 in the morning" — how a person says the time. */
    protected function spokenTime(string $hhmm, string $language): string
    {
        [$h, $m] = array_map('intval', array_pad(explode(':', $hhmm), 2, '0'));
        $h12 = $h % 12 ?: 12;
        $clock = $m > 0 ? sprintf('%d:%02d', $h12, $m) : (string) $h12;

        // Daypart word instead of AM/PM.
        $part = match (true) {
            $h < 12 => 0,  // morning
            $h < 17 => 1,  // afternoon
            $h < 20 => 2,  // evening
            default => 3,  // night
        };

        return match ($language) {
            'ur' => ['صبح', 'دوپہر', 'شام', 'رات'][$part]." {$clock} بجے",
            'fa' => "{$clock} ".['صبح', 'بعدازظهر', 'عصر', 'شب'][$part],
            'ps' => ['سهار', 'غرمې', 'ماښام', 'شپې'][$part]." {$clock} بجې",
            'sd' => ['صبح', 'منجهند', 'شام', 'رات'][$part]." {$clock} وڳي",
            default => "{$clock} ".['in the morning', 'in the afternoon', 'in the evening', 'at night'][$part],
        };
    }

    /** Vaccine name the way it is SAID in each language (script, no acronym soup). */
    protected function spokenVaccineName(string $vaccine, string $language): string
    {
        if ($vaccine === 'bcg') {
            return match ($language) {
                'ur', 'fa', 'ps' => 'بی سی جی',
                'sd' => 'بي سي جي',
                default => 'BCG',
            };
        }

        // MR — say the measles name people actually use.
        return match ($language) {
            'ur' => 'ایم آر یعنی خسرہ',
            'fa' => 'ام آر یعنی سرخک',
            'ps' => 'ایم آر یعنې شرۍ',
            'sd' => 'ايم آر',
            // Plain "MR" — the sentences around it already say "vaccine".
            default => 'MR',
        };
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
                'bcg_day' => $s->bcgDay(),
                'mr_day' => $s->mrDay(),
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
