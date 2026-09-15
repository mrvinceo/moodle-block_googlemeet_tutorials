<?php
// This file is part of Moodle - http://moodle.org/

namespace block_googlemeet_tutorials\local;

defined('MOODLE_INTERNAL') || die();

use Google_Client;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_EventAttendee;
use Google_Service_Calendar_EventDateTime;
use Google_Service_Calendar_ConferenceSolutionKey;
use Google_Service_Calendar_CreateConferenceRequest;
use Google_Service_Calendar_ConferenceData;

/**
 * Google Calendar operations for tutorial slots (host token).
 */
final class calendar_api {

    /**
     * Create a calendar event with Meet and optional attendees.
     *
     * @param Google_Client $client
     * @param string $timezone PHP timezone string
     * @param string $summary
     * @param string $description
     * @param int $timestart Unix timestamp
     * @param int $timeend Unix timestamp
     * @param array $attendees [['email' => x, 'displayname' => y], ...]
     * @return \Google_Service_Calendar_Event
     */
    public static function create_meet_event(
        Google_Client $client,
        string $timezone,
        string $summary,
        string $description,
        int $timestart,
        int $timeend,
        array $attendees = []
    ): \Google_Service_Calendar_Event {
        \block_googlemeet_tutorials_google_sdk_bootstrap();
        $service = new Google_Service_Calendar($client);

        $startdt = self::format_event_datetime($timestart, $timezone);
        $enddt = self::format_event_datetime($timeend, $timezone);

        $start = new Google_Service_Calendar_EventDateTime();
        $start->setDateTime($startdt);
        $start->setTimeZone($timezone);
        $end = new Google_Service_Calendar_EventDateTime();
        $end->setDateTime($enddt);
        $end->setTimeZone($timezone);

        $event = new Google_Service_Calendar_Event();
        $event->setSummary($summary);
        $event->setDescription(self::normalise_description($description));
        $event->setStart($start);
        $event->setEnd($end);

        $solutionkey = new Google_Service_Calendar_ConferenceSolutionKey();
        $solutionkey->setType('hangoutsMeet');
        $confrequest = new Google_Service_Calendar_CreateConferenceRequest();
        $confrequest->setRequestId((string)random_int(100000, 9999999));
        $confrequest->setConferenceSolutionKey($solutionkey);
        $confdata = new Google_Service_Calendar_ConferenceData();
        $confdata->setCreateRequest($confrequest);
        $event->setConferenceData($confdata);

        // Create Meet on the host calendar first (no attendees). Combining Meet creation
        // and attendee invites in one insert often fails with Google Calendar API errors.
        $list = self::build_attendee_list($attendees);
        $created = self::execute_with_retry(function() use ($service, $event, $list) {
            return $service->events->insert('primary', $event, [
                'conferenceDataVersion' => 1,
                'sendUpdates' => $list ? 'none' : 'all',
            ]);
        });

        if (!$list) {
            $eventid = $created->getId();
            if (!$created->getHangoutLink()) {
                return self::execute_with_retry(function() use ($service, $eventid) {
                    return $service->events->get('primary', $eventid);
                });
            }
            return $created;
        }

        $patch = new Google_Service_Calendar_Event();
        $patch->setAttendees($list);
        $updated = self::execute_with_retry(function() use ($service, $created, $patch) {
            return $service->events->patch('primary', $created->getId(), $patch, [
                'conferenceDataVersion' => 0,
                'sendUpdates' => 'all',
            ]);
        });

        if (!$updated->getHangoutLink()) {
            return self::execute_with_retry(function() use ($service, $created) {
                return $service->events->get('primary', $created->getId());
            });
        }
        return $updated;
    }

    /**
     * @param array $attendees
     * @return Google_Service_Calendar_EventAttendee[]
     */
    private static function build_attendee_list(array $attendees): array {
        $list = [];
        foreach ($attendees as $a) {
            if (empty($a['email'])) {
                continue;
            }
            $att = new Google_Service_Calendar_EventAttendee();
            $att->setEmail($a['email']);
            if (!empty($a['displayname'])) {
                $att->setDisplayName($a['displayname']);
            }
            $list[] = $att;
        }
        return $list;
    }

    private static function normalise_description(string $description): string {
        if ($description === '') {
            return '';
        }
        if (function_exists('html_to_text')) {
            $description = html_to_text($description, 0);
        } else {
            $description = strip_tags($description);
        }
        if (class_exists('\core_text')) {
            return \core_text::substr($description, 0, 8000);
        }
        return substr($description, 0, 8000);
    }

    /**
     * Create a recurring calendar event with Meet (no attendees).
     */
    public static function create_recurring_meet_event(
        Google_Client $client,
        string $timezone,
        string $summary,
        string $description,
        int $timestart,
        int $timeend,
        string $rrule
    ): \Google_Service_Calendar_Event {
        \block_googlemeet_tutorials_google_sdk_bootstrap();
        $service = new Google_Service_Calendar($client);

        $start = new Google_Service_Calendar_EventDateTime();
        $start->setDateTime(self::format_event_datetime($timestart, $timezone));
        $start->setTimeZone($timezone);
        $end = new Google_Service_Calendar_EventDateTime();
        $end->setDateTime(self::format_event_datetime($timeend, $timezone));
        $end->setTimeZone($timezone);

        $event = new Google_Service_Calendar_Event();
        $event->setSummary($summary);
        $event->setDescription(self::normalise_description($description));
        $event->setStart($start);
        $event->setEnd($end);
        $event->setRecurrence(['RRULE:' . $rrule]);

        $solutionkey = new Google_Service_Calendar_ConferenceSolutionKey();
        $solutionkey->setType('hangoutsMeet');
        $confrequest = new Google_Service_Calendar_CreateConferenceRequest();
        $confrequest->setRequestId((string)random_int(100000, 9999999));
        $confrequest->setConferenceSolutionKey($solutionkey);
        $confdata = new Google_Service_Calendar_ConferenceData();
        $confdata->setCreateRequest($confrequest);
        $event->setConferenceData($confdata);

        $created = $service->events->insert('primary', $event, [
            'conferenceDataVersion' => 1,
            'sendUpdates' => 'none',
        ]);

        if (!$created->getHangoutLink()) {
            return $service->events->get('primary', $created->getId());
        }
        return $created;
    }

    /**
     * List expanded instances of a recurring event.
     *
     * @return array<int,array{id:string,timestart:int,meeturl:string}>
     */
    public static function list_instances(Google_Client $client, string $recurringeventid): array {
        \block_googlemeet_tutorials_google_sdk_bootstrap();
        $service = new Google_Service_Calendar($client);
        $instances = [];
        $pagetoken = null;

        do {
            $params = [
                'singleEvents' => true,
                'orderBy' => 'startTime',
            ];
            if ($pagetoken) {
                $params['pageToken'] = $pagetoken;
            }
            $result = $service->events->instances('primary', $recurringeventid, $params);
            foreach ($result->getItems() as $item) {
                $start = $item->getStart();
                if (!$start || !$start->getDateTime()) {
                    continue;
                }
                $instances[] = [
                    'id' => $item->getId(),
                    'timestart' => strtotime($start->getDateTime()),
                    'meeturl' => $item->getHangoutLink() ?: '',
                ];
            }
            $pagetoken = $result->getNextPageToken();
        } while ($pagetoken);

        return $instances;
    }

    /**
     * Patch the master recurring event text and optionally times/RRULE.
     */
    public static function patch_recurring_master(
        Google_Client $client,
        string $recurringeventid,
        string $timezone,
        string $summary,
        string $description,
        ?int $timestart = null,
        ?int $timeend = null,
        ?string $rrule = null
    ): void {
        \block_googlemeet_tutorials_google_sdk_bootstrap();
        $service = new Google_Service_Calendar($client);

        $patch = new Google_Service_Calendar_Event();
        $patch->setSummary($summary);
        $patch->setDescription(self::normalise_description($description));

        if ($timestart !== null && $timeend !== null) {
            $start = new Google_Service_Calendar_EventDateTime();
            $start->setDateTime(self::format_event_datetime($timestart, $timezone));
            $start->setTimeZone($timezone);
            $end = new Google_Service_Calendar_EventDateTime();
            $end->setDateTime(self::format_event_datetime($timeend, $timezone));
            $end->setTimeZone($timezone);
            $patch->setStart($start);
            $patch->setEnd($end);
        }
        if ($rrule !== null) {
            $patch->setRecurrence(['RRULE:' . $rrule]);
        }

        $service->events->patch('primary', $recurringeventid, $patch, [
            'conferenceDataVersion' => 0,
            'sendUpdates' => 'all',
        ]);
    }

    /**
     * Delete the master recurring event (all instances).
     */
    public static function delete_recurring_master(Google_Client $client, string $recurringeventid): void {
        self::delete_event($client, $recurringeventid);
    }

    /**
     * Patch event start/end and text fields.
     */
    public static function patch_event_times(
        Google_Client $client,
        string $googleeventid,
        string $timezone,
        string $summary,
        string $description,
        int $timestart,
        int $timeend
    ): void {
        \block_googlemeet_tutorials_google_sdk_bootstrap();
        $service = new Google_Service_Calendar($client);

        $start = new Google_Service_Calendar_EventDateTime();
        $start->setDateTime(self::format_event_datetime($timestart, $timezone));
        $start->setTimeZone($timezone);
        $end = new Google_Service_Calendar_EventDateTime();
        $end->setDateTime(self::format_event_datetime($timeend, $timezone));
        $end->setTimeZone($timezone);

        $patch = new Google_Service_Calendar_Event();
        $patch->setSummary($summary);
        $patch->setDescription($description);
        $patch->setStart($start);
        $patch->setEnd($end);

        $service->events->patch('primary', $googleeventid, $patch, [
            'conferenceDataVersion' => 0,
            'sendUpdates' => 'all',
        ]);
    }

    /**
     * Patch summary/description only.
     */
    public static function patch_event_text(
        Google_Client $client,
        string $googleeventid,
        string $summary,
        string $description
    ): void {
        \block_googlemeet_tutorials_google_sdk_bootstrap();
        $service = new Google_Service_Calendar($client);
        $patch = new Google_Service_Calendar_Event();
        $patch->setSummary($summary);
        $patch->setDescription($description);
        $service->events->patch('primary', $googleeventid, $patch, [
            'sendUpdates' => 'all',
        ]);
    }

    public static function add_attendee(
        Google_Client $client,
        string $googleeventid,
        string $email,
        string $displayname = ''
    ): Google_Service_Calendar_Event {
        \block_googlemeet_tutorials_google_sdk_bootstrap();
        $service = new Google_Service_Calendar($client);
        $event = self::execute_with_retry(function() use ($service, $googleeventid) {
            return $service->events->get('primary', $googleeventid);
        });
        $attendees = $event->getAttendees() ?: [];
        foreach ($attendees as $existing) {
            if (strtolower($existing->getEmail()) === strtolower($email)) {
                return $event;
            }
        }
        $att = new Google_Service_Calendar_EventAttendee();
        $att->setEmail($email);
        if ($displayname !== '') {
            $att->setDisplayName($displayname);
        }
        $attendees[] = $att;

        $patch = new Google_Service_Calendar_Event();
        $patch->setAttendees($attendees);
        self::execute_with_retry(function() use ($service, $googleeventid, $patch) {
            return $service->events->patch('primary', $googleeventid, $patch, [
                'conferenceDataVersion' => 0,
                'sendUpdates' => 'all',
            ]);
        });
        return $event;
    }

    public static function remove_attendee(
        Google_Client $client,
        string $googleeventid,
        string $email
    ): void {
        \block_googlemeet_tutorials_google_sdk_bootstrap();
        $service = new Google_Service_Calendar($client);
        $event = self::execute_with_retry(function() use ($service, $googleeventid) {
            return $service->events->get('primary', $googleeventid);
        });
        $attendees = $event->getAttendees() ?: [];
        $filtered = [];
        foreach ($attendees as $existing) {
            if (strtolower($existing->getEmail()) !== strtolower($email)) {
                $filtered[] = $existing;
            }
        }
        $patch = new Google_Service_Calendar_Event();
        $patch->setAttendees($filtered);
        self::execute_with_retry(function() use ($service, $googleeventid, $patch) {
            return $service->events->patch('primary', $googleeventid, $patch, [
                'conferenceDataVersion' => 0,
                'sendUpdates' => 'all',
            ]);
        });
    }

    public static function delete_event(Google_Client $client, string $googleeventid): void {
        \block_googlemeet_tutorials_google_sdk_bootstrap();
        $service = new Google_Service_Calendar($client);
        self::execute_with_retry(function() use ($service, $googleeventid) {
            return $service->events->delete('primary', $googleeventid, [
                'sendUpdates' => 'all',
            ]);
        });
    }

    private static function format_event_datetime(int $timestamp, string $timezone): string {
        try {
            $dtz = new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $dtz = new \DateTimeZone('UTC');
        }
        $dt = new \DateTime('@' . $timestamp);
        $dt->setTimezone($dtz);
        return $dt->format('Y-m-d\TH:i:s');
    }

    /**
     * Run a Google Calendar API call with short retries on rate-limit responses.
     *
     * @param callable $fn
     * @return mixed
     */
    public static function execute_with_retry(callable $fn) {
        $attempt = 0;
        $delay = 1;
        while (true) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt >= 4 || !self::is_rate_limit_error($e)) {
                    throw $e;
                }
                sleep($delay);
                $delay *= 2;
            }
        }
    }

    /**
     * @param \Throwable $e
     * @return bool
     */
    public static function is_rate_limit_error(\Throwable $e): bool {
        $msg = $e->getMessage();
        if (stripos($msg, 'rateLimitExceeded') !== false
                || stripos($msg, 'userRateLimitExceeded') !== false
                || stripos($msg, 'quotaExceeded') !== false
                || stripos($msg, 'Rate Limit Exceeded') !== false) {
            return true;
        }
        if (method_exists($e, 'getCode') && (int) $e->getCode() === 403) {
            return stripos($msg, 'rate') !== false || stripos($msg, 'quota') !== false;
        }
        return false;
    }

    /**
     * Fetch a calendar event by id.
     *
     * @param Google_Client $client
     * @param string $googleeventid
     * @return Google_Service_Calendar_Event
     */
    public static function get_event(Google_Client $client, string $googleeventid): Google_Service_Calendar_Event {
        \block_googlemeet_tutorials_google_sdk_bootstrap();
        $service = new Google_Service_Calendar($client);
        return self::execute_with_retry(function() use ($service, $googleeventid) {
            return $service->events->get('primary', $googleeventid);
        });
    }

    /**
     * Extract unix start/end and Meet URL from a Google event.
     *
     * @param Google_Service_Calendar_Event $event
     * @param string $fallbacktimezone
     * @return array{timestart:int,timeend:int,meeturl:string}|null
     */
    public static function event_time_data(Google_Service_Calendar_Event $event, string $fallbacktimezone): ?array {
        $start = $event->getStart();
        $end = $event->getEnd();
        if (!$start || !$end) {
            return null;
        }
        $startraw = $start->getDateTime() ?: $start->getDate();
        $endraw = $end->getDateTime() ?: $end->getDate();
        if (!$startraw || !$endraw) {
            return null;
        }
        $tzname = $start->getTimeZone() ?: $fallbacktimezone;
        try {
            $tz = new \DateTimeZone($tzname);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }
        try {
            $startdt = new \DateTimeImmutable($startraw, $tz);
            $enddt = new \DateTimeImmutable($endraw, $tz);
        } catch (\Throwable $e) {
            return null;
        }
        return [
            'timestart' => $startdt->getTimestamp(),
            'timeend' => $enddt->getTimestamp(),
            'meeturl' => (string) ($event->getHangoutLink() ?: ''),
        ];
    }
}
