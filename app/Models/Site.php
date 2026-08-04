<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    /**
     * Standard EPI site hours — used for every site unless it is specifically
     * overridden in the admin (NULL columns mean "use the default").
     */
    public const DEFAULT_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public const DEFAULT_OPEN = '09:00';

    public const DEFAULT_CLOSE = '14:00';

    /** Week order + display labels for the timing label formatter. */
    public const DAY_LABELS = [
        'mon' => 'Mon',
        'tue' => 'Tue',
        'wed' => 'Wed',
        'thu' => 'Thu',
        'fri' => 'Fri',
        'sat' => 'Sat',
        'sun' => 'Sun',
    ];

    protected $fillable = [
        'district',
        'union_council',
        'fix_site',
        'outreach_site',
        'latitude',
        'longitude',
        // Structured opening hours (see timingLabel()). NULL = standard hours.
        'timing_days',
        'open_time',
        'close_time',
        // Optional mid-day break window. NULL = no break.
        'break_start',
        'break_end',
        // Weekly vaccine session days (mon..sun). BCG and MR are multi-dose
        // vials opened on fixed weekdays per the SHR UC schedule.
        'bcg_day',
        'mr_day',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'timing_days' => 'array',
    ];

    /**
     * Operating days, normalized to week order. Falls back to the standard
     * Mon–Sat when unset or when the stored value contains no valid days.
     *
     * @return array<int, string>
     */
    public function timingDays(): array
    {
        $stored = array_map(
            fn ($d) => mb_strtolower(trim((string) $d)),
            is_array($this->timing_days) ? $this->timing_days : [],
        );
        $days = array_values(array_intersect(array_keys(self::DAY_LABELS), $stored));

        return ! empty($days) ? $days : self::DEFAULT_DAYS;
    }

    /** Opening time as "HH:MM" (24h), defaulted. */
    public function openTime(): string
    {
        return self::normalizeTime((string) $this->open_time) ?? self::DEFAULT_OPEN;
    }

    /** Closing time as "HH:MM" (24h), defaulted. */
    public function closeTime(): string
    {
        return self::normalizeTime((string) $this->close_time) ?? self::DEFAULT_CLOSE;
    }

    /** Break start as "HH:MM", or null when the site has no break. */
    public function breakStart(): ?string
    {
        return self::normalizeTime((string) $this->break_start);
    }

    /** Break end as "HH:MM", or null when the site has no break. */
    public function breakEnd(): ?string
    {
        return self::normalizeTime((string) $this->break_end);
    }

    /** Whether the site has a (complete) mid-day break window. */
    public function hasBreak(): bool
    {
        return $this->breakStart() !== null && $this->breakEnd() !== null;
    }

    /** Normalize a stored/user day value to a mon..sun code, or null. */
    public static function normalizeDay(?string $day): ?string
    {
        $d = mb_strtolower(trim((string) $day));
        if (isset(self::DAY_LABELS[$d])) {
            return $d;
        }
        // Accept full English day names ("Saturday", "friday ").
        foreach (array_keys(self::DAY_LABELS) as $code) {
            if (str_starts_with($d, $code)) {
                return $code;
            }
        }

        return null;
    }

    /** BCG session day code (mon..sun), or null when not scheduled. */
    public function bcgDay(): ?string
    {
        return self::normalizeDay($this->bcg_day);
    }

    /** MR session day code (mon..sun), or null when not scheduled. */
    public function mrDay(): ?string
    {
        return self::normalizeDay($this->mr_day);
    }

    /**
     * Compact human label for the app's site cards, built from the structured
     * fields: consecutive day runs collapse to ranges and times render 12-hour.
     * Defaults produce "Mon-Sat 9AM-2PM"; [mon,wed,fri] 08:00–13:30 with a
     * 12:00–12:30 break produces "Mon, Wed, Fri 8AM-1:30PM (break 12PM-12:30PM)".
     */
    public function timingLabel(): string
    {
        $label = $this->daysLabel().' '.self::formatTime($this->openTime()).'-'.self::formatTime($this->closeTime());
        if ($this->hasBreak()) {
            $label .= ' (break '.self::formatTime($this->breakStart()).'-'.self::formatTime($this->breakEnd()).')';
        }

        return $label;
    }

    /** Day-set label with consecutive runs collapsed ("Mon-Sat", "Mon-Wed, Fri"). */
    public function daysLabel(): string
    {
        $order = array_keys(self::DAY_LABELS);
        $index = array_flip($order);
        $days = $this->timingDays();

        $runs = [];
        $start = $prev = null;
        foreach ($days as $day) {
            if ($start === null) {
                $start = $prev = $day;

                continue;
            }
            if ($index[$day] === $index[$prev] + 1) {
                $prev = $day;

                continue;
            }
            $runs[] = [$start, $prev];
            $start = $prev = $day;
        }
        $runs[] = [$start, $prev];

        $parts = array_map(
            fn (array $run): string => $run[0] === $run[1]
                ? self::DAY_LABELS[$run[0]]
                : self::DAY_LABELS[$run[0]].'-'.self::DAY_LABELS[$run[1]],
            $runs,
        );

        return implode(', ', $parts);
    }

    /** "HH:MM" (24h) → compact 12-hour label: 09:00 → 9AM, 13:30 → 1:30PM. */
    public static function formatTime(string $time): string
    {
        $normalized = self::normalizeTime($time) ?? $time;
        [$h, $m] = array_map('intval', array_pad(explode(':', $normalized), 2, '0'));
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 ?: 12;

        return $m > 0 ? sprintf('%d:%02d%s', $h12, $m, $suffix) : $h12.$suffix;
    }

    /**
     * Coerce a stored/user time value to "HH:MM", or null when unparseable.
     * Accepts "9:00", "09:00", and picker values like "09:00:00".
     */
    public static function normalizeTime(?string $time): ?string
    {
        $time = trim((string) $time);
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $min);
    }
}
