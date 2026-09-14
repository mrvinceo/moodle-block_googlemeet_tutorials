<?php
// This file is part of Moodle - http://moodle.org/

namespace block_googlemeet_tutorials\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Recurrence presets, RRULE building, and occurrence expansion for tutorial series.
 */
final class recurrence {

    public const MAX_OCCURRENCES = 100;

    public const PRESET_DAILY = 'daily';
    public const PRESET_WEEKLY = 'weekly';
    public const PRESET_MONTHLY_WEEKDAY = 'monthly_weekday';
    public const PRESET_MONTHLY_DAY = 'monthly_day';
    public const PRESET_WEEKDAYS = 'weekdays';
    public const PRESET_ANNUALLY = 'annually';
    public const PRESET_CUSTOM = 'custom';

    /** @var array<int,string> PHP w (0=Sun) to RFC BYDAY */
    private const WEEKDAY_MAP = [
        0 => 'SU',
        1 => 'MO',
        2 => 'TU',
        3 => 'WE',
        4 => 'TH',
        5 => 'FR',
        6 => 'SA',
    ];

    /**
     * Preset keys and human-readable labels derived from the first occurrence start.
     *
     * @param int $timestart Unix timestamp of first occurrence.
     * @param string $timezone PHP timezone name.
     * @return array<string,string> preset key => label
     */
    public static function build_preset_options(int $timestart, string $timezone): array {
        $dt = self::timestamp_to_datetime($timestart, $timezone);
        $weekday = (int) $dt->format('w');
        $weekdayname = self::weekday_label($weekday);
        $dayofmonth = (int) $dt->format('j');
        $ordinal = self::ordinal_week_of_month($dt);
        $ordinalword = self::ordinal_word($ordinal);
        $monthday = userdate($timestart, get_string('strftimedatefullshort', 'langconfig'), $timezone);

        return [
            self::PRESET_DAILY => get_string('repeatpreset_daily', 'block_googlemeet_tutorials'),
            self::PRESET_WEEKLY => get_string('repeatpreset_weekly', 'block_googlemeet_tutorials', $weekdayname),
            self::PRESET_MONTHLY_WEEKDAY => get_string(
                'repeatpreset_monthly_weekday',
                'block_googlemeet_tutorials',
                (object) ['ordinal' => $ordinalword, 'weekday' => $weekdayname]
            ),
            self::PRESET_MONTHLY_DAY => get_string('repeatpreset_monthly_day', 'block_googlemeet_tutorials', $dayofmonth),
            self::PRESET_WEEKDAYS => get_string('repeatpreset_weekdays', 'block_googlemeet_tutorials'),
            self::PRESET_ANNUALLY => get_string('repeatpreset_annually', 'block_googlemeet_tutorials', $monthday),
            self::PRESET_CUSTOM => get_string('repeatpreset_custom', 'block_googlemeet_tutorials'),
        ];
    }

    /**
     * Build an RRULE string (without the "RRULE:" prefix) including UNTIL in UTC.
     *
     * @param array $config Keys: preset, timestart, recuruntil, timezone, recurrence_interval?,
     *                      recurrence_frequency?, recurrence_byday?
     * @return string
     */
    public static function build_rrule(array $config): string {
        $preset = $config['preset'] ?? self::PRESET_WEEKLY;
        $timestart = (int) $config['timestart'];
        $recuruntil = (int) $config['recuruntil'];
        $timezone = $config['timezone'] ?? 'UTC';
        $interval = max(1, (int) ($config['recurrence_interval'] ?? 1));

        $dt = self::timestamp_to_datetime($timestart, $timezone);
        $byday = self::WEEKDAY_MAP[(int) $dt->format('w')];
        $dayofmonth = (int) $dt->format('j');
        $setpos = self::ordinal_week_of_month($dt);

        $parts = [];
        switch ($preset) {
            case self::PRESET_DAILY:
                $parts = ['FREQ=DAILY', 'INTERVAL=1'];
                break;
            case self::PRESET_WEEKLY:
                $parts = ['FREQ=WEEKLY', 'INTERVAL=1', 'BYDAY=' . $byday];
                break;
            case self::PRESET_MONTHLY_WEEKDAY:
                $parts = ['FREQ=MONTHLY', 'INTERVAL=1', 'BYDAY=' . $setpos . $byday];
                break;
            case self::PRESET_MONTHLY_DAY:
                $parts = ['FREQ=MONTHLY', 'INTERVAL=1', 'BYMONTHDAY=' . $dayofmonth];
                break;
            case self::PRESET_WEEKDAYS:
                $parts = ['FREQ=WEEKLY', 'INTERVAL=1', 'BYDAY=MO,TU,WE,TH,FR'];
                break;
            case self::PRESET_ANNUALLY:
                $parts = ['FREQ=YEARLY', 'INTERVAL=1'];
                break;
            case self::PRESET_CUSTOM:
            default:
                $freq = strtoupper((string) ($config['recurrence_frequency'] ?? 'WEEKLY'));
                $parts = ['FREQ=' . $freq, 'INTERVAL=' . $interval];
                if ($freq === 'WEEKLY' && !empty($config['recurrence_byday'])) {
                    $parts[] = 'BYDAY=' . strtoupper((string) $config['recurrence_byday']);
                }
                break;
        }

        $parts[] = 'UNTIL=' . self::recuruntil_to_rrule_until($recuruntil, $timezone);
        return implode(';', $parts);
    }

    /**
     * Expand a recurrence into occurrence start/end timestamps.
     *
     * @param int $timestart First occurrence start.
     * @param int $duration Seconds between start and end.
     * @param array $config Same keys as build_rrule().
     * @param string $timezone PHP timezone name.
     * @param int $maxcount Maximum occurrences (default MAX_OCCURRENCES).
     * @return array<int,array{timestart:int,timeend:int}>
     */
    public static function expand_occurrences(
        int $timestart,
        int $duration,
        array $config,
        string $timezone,
        int $maxcount = self::MAX_OCCURRENCES
    ): array {
        $preset = $config['preset'] ?? self::PRESET_WEEKLY;
        $recuruntil = (int) $config['recuruntil'];
        $interval = max(1, (int) ($config['recurrence_interval'] ?? 1));

        $occurrences = [];
        $cursor = self::timestamp_to_datetime($timestart, $timezone);
        $endlimit = self::timestamp_to_datetime($recuruntil, $timezone);
        $endlimit->setTime(23, 59, 59);

        $add = function(\DateTime $dt) use (&$occurrences, $duration, $timezone, $endlimit, $maxcount): bool {
            if (count($occurrences) >= $maxcount) {
                return false;
            }
            $ts = $dt->getTimestamp();
            if ($ts > $endlimit->getTimestamp()) {
                return false;
            }
            $occurrences[] = [
                'timestart' => $ts,
                'timeend' => $ts + $duration,
            ];
            return true;
        };

        switch ($preset) {
            case self::PRESET_DAILY:
                while ($add($cursor)) {
                    $cursor->modify('+1 day');
                }
                break;

            case self::PRESET_WEEKLY:
                while ($add($cursor)) {
                    $cursor->modify('+1 week');
                }
                break;

            case self::PRESET_MONTHLY_WEEKDAY:
                $setpos = self::ordinal_week_of_month($cursor);
                $byday = (int) $cursor->format('w');
                $hour = (int) $cursor->format('H');
                $minute = (int) $cursor->format('i');
                $second = (int) $cursor->format('s');
                while (count($occurrences) < $maxcount) {
                    $candidate = self::nth_weekday_in_month(
                        (int) $cursor->format('Y'),
                        (int) $cursor->format('n'),
                        $byday,
                        $setpos,
                        $timezone
                    );
                    if ($candidate) {
                        $candidate->setTime($hour, $minute, $second);
                        if ($candidate->getTimestamp() > $endlimit->getTimestamp()) {
                            break;
                        }
                        if ($candidate->getTimestamp() >= $timestart && !$add($candidate)) {
                            break;
                        }
                    }
                    $cursor->modify('first day of next month');
                }
                break;

            case self::PRESET_MONTHLY_DAY:
                $day = (int) $cursor->format('j');
                $hour = (int) $cursor->format('H');
                $minute = (int) $cursor->format('i');
                $second = (int) $cursor->format('s');
                while (count($occurrences) < $maxcount) {
                    $daysinmonth = (int) $cursor->format('t');
                    if ($day <= $daysinmonth) {
                        $candidate = self::make_datetime(
                            (int) $cursor->format('Y'),
                            (int) $cursor->format('n'),
                            $day,
                            $hour,
                            $minute,
                            $second,
                            $timezone
                        );
                        if ($candidate->getTimestamp() > $endlimit->getTimestamp()) {
                            break;
                        }
                        if ($candidate->getTimestamp() >= $timestart && !$add($candidate)) {
                            break;
                        }
                    }
                    $cursor->modify('first day of next month');
                }
                break;

            case self::PRESET_WEEKDAYS:
                while ($add($cursor)) {
                    do {
                        $cursor->modify('+1 day');
                    } while ((int) $cursor->format('N') > 5);
                }
                break;

            case self::PRESET_ANNUALLY:
                while ($add($cursor)) {
                    $cursor->modify('+1 year');
                }
                break;

            case self::PRESET_CUSTOM:
            default:
                $freq = strtoupper((string) ($config['recurrence_frequency'] ?? 'WEEKLY'));
                if ($freq === 'DAILY') {
                    while ($add($cursor)) {
                        $cursor->modify('+' . $interval . ' day');
                    }
                } else if ($freq === 'WEEKLY') {
                    $bydays = [];
                    if (!empty($config['recurrence_byday'])) {
                        $bydays = array_map('trim', explode(',', strtoupper((string) $config['recurrence_byday'])));
                    } else {
                        $bydays = [self::WEEKDAY_MAP[(int) $cursor->format('w')]];
                    }
                    $rfcmap = array_flip(self::WEEKDAY_MAP);
                    $targetdays = [];
                    foreach ($bydays as $d) {
                        if (isset($rfcmap[$d])) {
                            $targetdays[] = $rfcmap[$d];
                        }
                    }
                    sort($targetdays);
                    if (!$targetdays) {
                        $targetdays = [(int) $cursor->format('w')];
                    }
                    $hour = (int) $cursor->format('H');
                    $minute = (int) $cursor->format('i');
                    $second = (int) $cursor->format('s');
                    $check = clone $cursor;
                    $check->setTime(0, 0, 0);
                    $safety = 0;
                    while (count($occurrences) < $maxcount && $safety < 5000) {
                        $safety++;
                        $w = (int) $check->format('w');
                        if (in_array($w, $targetdays, true)) {
                            $candidate = clone $check;
                            $candidate->setTime($hour, $minute, $second);
                            if ($candidate->getTimestamp() >= $timestart) {
                                if ($candidate->getTimestamp() > $endlimit->getTimestamp()) {
                                    break;
                                }
                                if (!$add($candidate)) {
                                    break;
                                }
                            }
                        }
                        $check->modify('+1 day');
                        if ((int) $check->format('w') === (int) $cursor->format('w') && $check > $cursor) {
                            $check->modify('+' . ($interval - 1) . ' week');
                        }
                    }
                } else if ($freq === 'MONTHLY') {
                    while ($add($cursor)) {
                        $cursor->modify('+' . $interval . ' month');
                    }
                } else if ($freq === 'YEARLY') {
                    while ($add($cursor)) {
                        $cursor->modify('+' . $interval . ' year');
                    }
                }
                break;
        }

        return $occurrences;
    }

    /**
     * End of recur-until day as RRULE UNTIL value (UTC).
     */
    public static function recuruntil_to_rrule_until(int $recuruntil, string $timezone): string {
        $dt = self::timestamp_to_datetime($recuruntil, $timezone);
        $dt->setTime(23, 59, 59);
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Ymd\THis\Z');
    }

    /**
     * Default recur-until timestamp (~12 weeks from start).
     */
    public static function default_recuruntil(int $timestart, string $timezone): int {
        $dt = self::timestamp_to_datetime($timestart, $timezone);
        $dt->modify('+12 weeks');
        return $dt->getTimestamp();
    }

    /**
     * @param int|array $recuruntil Timestamp from date_selector, or year/month/day parts.
     * @param string $timezone
     * @return int End of that day in user timezone.
     */
    public static function normalise_recuruntil($recuruntil, string $timezone): int {
        if (is_array($recuruntil)) {
            $dt = new \DateTime('now', new \DateTimeZone($timezone));
            $dt->setDate(
                (int) $recuruntil['year'],
                (int) $recuruntil['month'],
                (int) $recuruntil['day']
            );
        } else {
            $dt = self::timestamp_to_datetime((int) $recuruntil, $timezone);
        }
        $dt->setTime(23, 59, 59);
        return $dt->getTimestamp();
    }

    /**
     * @param int $timestamp
     * @param string $timezone
     * @return \DateTime
     */
    private static function timestamp_to_datetime(int $timestamp, string $timezone): \DateTime {
        $dt = new \DateTime('@' . $timestamp);
        $dt->setTimezone(new \DateTimeZone($timezone));
        return $dt;
    }

    /**
     * Build a DateTime from date/time parts in a given timezone.
     */
    private static function make_datetime(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $timezone
    ): \DateTime {
        $dt = new \DateTime('now', new \DateTimeZone($timezone));
        $dt->setDate($year, $month, $day);
        $dt->setTime($hour, $minute, $second);
        return $dt;
    }

    private static function weekday_label(int $w): string {
        $keys = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        return get_string($keys[$w] ?? 'monday', 'calendar');
    }

    /**
     * Ordinal week of month (1-4, or -1 for last).
     */
    private static function ordinal_week_of_month(\DateTime $dt): int {
        $day = (int) $dt->format('j');
        $pos = (int) ceil($day / 7);
        $daysinmonth = (int) $dt->format('t');
        if ($day + 7 > $daysinmonth) {
            return -1;
        }
        return $pos;
    }

    private static function ordinal_word(int $pos): string {
        if ($pos === -1) {
            return get_string('repeatordinal_last', 'block_googlemeet_tutorials');
        }
        $map = [
            1 => get_string('repeatordinal_first', 'block_googlemeet_tutorials'),
            2 => get_string('repeatordinal_second', 'block_googlemeet_tutorials'),
            3 => get_string('repeatordinal_third', 'block_googlemeet_tutorials'),
            4 => get_string('repeatordinal_fourth', 'block_googlemeet_tutorials'),
        ];
        return $map[$pos] ?? (string) $pos;
    }

    /**
     * Find the nth weekday in a month (setpos 1-4 or -1 for last).
     */
    private static function nth_weekday_in_month(
        int $year,
        int $month,
        int $weekday,
        int $setpos,
        string $timezone
    ): ?\DateTime {
        $first = new \DateTime("$year-$month-01", new \DateTimeZone($timezone));
        $matches = [];
        $daysinmonth = (int) $first->format('t');
        for ($d = 1; $d <= $daysinmonth; $d++) {
            $candidate = new \DateTime("$year-$month-$d", new \DateTimeZone($timezone));
            if ((int) $candidate->format('w') === $weekday) {
                $matches[] = $candidate;
            }
        }
        if (!$matches) {
            return null;
        }
        if ($setpos === -1) {
            return $matches[count($matches) - 1];
        }
        return $matches[$setpos - 1] ?? null;
    }
}
