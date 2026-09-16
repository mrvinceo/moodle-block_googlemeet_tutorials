<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

use block_googlemeet_tutorials\local\calendar_api;
use block_googlemeet_tutorials\local\recurrence;

require_once($CFG->libdir . '/grouplib.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/classes/local/calendar_api.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/classes/local/recurrence.php');

/**
 * Load Google API client autoload and Calendar service resource classes.
 *
 * The Calendar service constructor instantiates every Resource\* class. On some
 * servers the PSR-4 autoloader does not resolve those until too late, causing
 * "Class Google\Service\Calendar\Resource\Acl not found". Preloading the
 * Resource PHP files avoids that.
 */
function block_googlemeet_tutorials_google_sdk_bootstrap(): void {
    global $CFG;
    static $done = false;
    if ($done) {
        return;
    }
    $autoload = $CFG->dirroot . '/blocks/googlemeet_tutorials/vendor/autoload.php';
    if (!is_readable($autoload)) {
        return;
    }
    require_once($autoload);

    $resdir = $CFG->dirroot . '/blocks/googlemeet_tutorials/vendor/google/apiclient-services/src/Calendar/Resource';
    if (is_dir($resdir)) {
        foreach (glob($resdir . '/*.php') ?: [] as $file) {
            require_once($file);
        }
    }
    $done = true;
}

/**
 * Redirect URI for OAuth (register this exact URI in Google Cloud Console).
 */
function block_googlemeet_tutorials_auth_callback_url(): moodle_url {
    return new moodle_url('/blocks/googlemeet_tutorials/auth.php');
}

/**
 * Persist refreshed access token for a user.
 */
function block_googlemeet_tutorials_persist_google_token(int $userid, \Google_Client $client): void {
    global $DB;
    if (!$client->getAccessToken()) {
        return;
    }
    $newtoken = json_encode($client->getAccessToken());
    if ($newtoken === false || $newtoken === 'null') {
        return;
    }
    $rec = $DB->get_record('block_googlemeet_tutorials_token', ['user_id' => $userid]);
    $row = (object) [
        'user_id' => $userid,
        'token' => $newtoken,
        'timecreated' => time(),
    ];
    if ($rec) {
        $row->id = $rec->id;
        if (!empty($rec->user_email)) {
            $row->user_email = $rec->user_email;
        }
        $DB->update_record('block_googlemeet_tutorials_token', $row);
    } else {
        $DB->insert_record('block_googlemeet_tutorials_token', $row);
    }
}

/**
 * Calendar API client for a user, or null if not connected / expired without refresh.
 */
function block_googlemeet_tutorials_get_calendar_client(int $userid): ?\Google_Client {
    global $CFG, $DB;

    $vendor = $CFG->dirroot . '/blocks/googlemeet_tutorials/vendor/autoload.php';
    if (!is_readable($vendor)) {
        return null;
    }
    block_googlemeet_tutorials_google_sdk_bootstrap();

    $cfg = get_config('block_googlemeet_tutorials');
    if (empty($cfg->clientid) || empty($cfg->clientsecret)) {
        return null;
    }

    $userrow = $DB->get_record('block_googlemeet_tutorials_token', ['user_id' => $userid]);
    if (!$userrow || empty($userrow->token) || $userrow->token === 'null') {
        return null;
    }

    $redirecturi = block_googlemeet_tutorials_auth_callback_url()->out(false);

    $client = new \Google_Client();
    $client->setApplicationName('Moodle Google Meet tutorials');
    $client->setScopes('https://www.googleapis.com/auth/calendar.events');
    if (!empty($cfg->apikey)) {
        $client->setDeveloperKey($cfg->apikey);
    }
    $client->setClientId($cfg->clientid);
    $client->setClientSecret($cfg->clientsecret);
    $client->setRedirectUri($redirecturi);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setAccessToken($userrow->token);

    if ($client->isAccessTokenExpired()) {
        $rt = $client->getRefreshToken();
        if ($rt) {
            $client->fetchAccessTokenWithRefreshToken($rt);
            if ($client->getAccessToken()) {
                block_googlemeet_tutorials_persist_google_token($userid, $client);
            }
        } else {
            return null;
        }
    }

    return $client;
}

/**
 * Build a Google client suitable for starting the OAuth browser flow (no stored token required).
 */
function block_googlemeet_tutorials_new_oauth_client(): \Google_Client {
    block_googlemeet_tutorials_google_sdk_bootstrap();
    $cfg = get_config('block_googlemeet_tutorials');
    $redirecturi = block_googlemeet_tutorials_auth_callback_url()->out(false);
    $client = new \Google_Client();
    $client->setApplicationName('Moodle Google Meet tutorials');
    $client->setScopes('https://www.googleapis.com/auth/calendar.events');
    if (!empty($cfg->apikey)) {
        $client->setDeveloperKey($cfg->apikey);
    }
    $client->setClientId($cfg->clientid);
    $client->setClientSecret($cfg->clientsecret);
    $client->setRedirectUri($redirecturi);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    return $client;
}

function block_googlemeet_tutorials_user_timezone(\stdClass $user): string {
    if (class_exists('\core_date')) {
        if (method_exists('\core_date', 'get_user_timezone_object')) {
            try {
                return \core_date::get_user_timezone_object($user)->getName();
            } catch (\Throwable $e) {
                // Fall through to string API.
            }
        }
        try {
            return \core_date::get_user_timezone($user);
        } catch (\Throwable $e) {
            // Fall through.
        }
    }
    global $CFG;
    if (!empty($user->timezone) && (string) $user->timezone !== '99') {
        return (string) $user->timezone;
    }
    $fallback = !empty($CFG->timezone) && (string) $CFG->timezone !== '99' ? $CFG->timezone : 'UTC';
    try {
        new \DateTimeZone($fallback);
        return $fallback;
    } catch (\Throwable $e) {
        return 'UTC';
    }
}

/**
 * Group ids in a course for a user (excluding grouping 0 meta if empty).
 */
function block_googlemeet_tutorials_user_course_groupids(int $courseid, int $userid): array {
    $g = groups_get_user_groups($courseid, $userid);
    return $g[0] ?? [];
}

function block_googlemeet_tutorials_users_share_course_group(int $courseid, int $userid1, int $userid2): bool {
    if ($userid1 === $userid2) {
        return true;
    }
    $a = block_googlemeet_tutorials_user_course_groupids($courseid, $userid1);
    $b = block_googlemeet_tutorials_user_course_groupids($courseid, $userid2);
    return count(array_intersect($a, $b)) > 0;
}

function block_googlemeet_tutorials_count_registrations(int $slotid): int {
    global $DB;
    return $DB->count_records('block_googlemeet_tutorials_reg', ['slotid' => $slotid]);
}

function block_googlemeet_tutorials_user_is_registered(int $slotid, int $userid): bool {
    global $DB;
    return $DB->record_exists('block_googlemeet_tutorials_reg', ['slotid' => $slotid, 'userid' => $userid]);
}

/**
 * Whether a host tutor has the manageslots capability in the given course context.
 * Static cache keyed by "hostuserid:courseid".
 */
function block_googlemeet_tutorials_host_manages_in_course(int $hostuserid, int $courseid): bool {
    static $cache = [];
    $key = $hostuserid . ':' . $courseid;
    if (!isset($cache[$key])) {
        $ctx = context_course::instance($courseid, IGNORE_MISSING);
        $cache[$key] = $ctx && has_capability('block/googlemeet_tutorials:manageslots', $ctx, $hostuserid);
    }
    return $cache[$key];
}

function block_googlemeet_tutorials_slot_visible_to_user(
    \stdClass $slot,
    int $courseid,
    int $userid,
    \context_course $context
): bool {
    if ((int) $slot->status !== 1) {
        return false;
    }
    $scope = (int) ($slot->scope ?? 0);
    if ($scope === 0) {
        // Course-scoped: must originate in the viewed course.
        if ((int) $slot->courseid !== $courseid) {
            return false;
        }
    } else {
        // Site-wide: host must have manageslots in the viewed course.
        if (!block_googlemeet_tutorials_host_manages_in_course((int) $slot->userid, $courseid)) {
            return false;
        }
    }
    if ((int) $slot->userid === $userid) {
        return true;
    }
    if (has_capability('block/googlemeet_tutorials:viewallslots', $context, $userid)) {
        return true;
    }
    $usegroups = !isset($slot->usegroups) || (int) $slot->usegroups === 1;
    if (!$usegroups) {
        // Slot is open to all students who can view the schedule.
        return true;
    }
    return block_googlemeet_tutorials_users_share_course_group($courseid, $userid, (int) $slot->userid);
}

function block_googlemeet_tutorials_user_can_view_meet_link(
    \stdClass $slot,
    int $courseid,
    int $userid,
    \context_course $context
): bool {
    if (empty($slot->meeturl)) {
        return false;
    }
    if (has_capability('block/googlemeet_tutorials:viewallslots', $context, $userid)) {
        return true;
    }
    if (block_googlemeet_tutorials_user_is_registered($slot->id, $userid)) {
        return true;
    }
    if (has_capability('block/googlemeet_tutorials:manageslots', $context, $userid)
            && block_googlemeet_tutorials_users_share_course_group($courseid, $userid, (int) $slot->userid)) {
        return true;
    }
    return false;
}

/**
 * Upcoming active slots visible in a course schedule:
 *  - course-scoped slots originating in the viewed course; plus
 *  - site-wide slots where the host has manageslots in the viewed course.
 */
function block_googlemeet_tutorials_fetch_course_slots(int $courseid, int $since = 0): array {
    global $DB;

    // Course-scoped slots for this course.
    $course = $DB->get_records_select(
        'block_googlemeet_tutorials_slot',
        'courseid = :c AND scope = 0 AND status = 1 AND timestart >= :t',
        ['c' => $courseid, 't' => $since],
        'timestart ASC'
    );

    // Site-wide slots: get all active future ones then filter by host capability.
    $sitewide = $DB->get_records_select(
        'block_googlemeet_tutorials_slot',
        'scope = 1 AND status = 1 AND timestart >= :t',
        ['t' => $since],
        'timestart ASC'
    );
    $eligible = [];
    foreach ($sitewide as $slot) {
        if ((int) $slot->courseid === $courseid) {
            // Already included in $course above.
            continue;
        }
        if (block_googlemeet_tutorials_host_manages_in_course((int) $slot->userid, $courseid)) {
            $eligible[$slot->id] = $slot;
        }
    }

    return array_merge($course, $eligible);
}

function block_googlemeet_tutorials_visible_slots(int $courseid, int $userid, \context_course $context): array {
    $out = [];
    foreach (block_googlemeet_tutorials_fetch_course_slots($courseid, time() - 3600) as $slot) {
        if (block_googlemeet_tutorials_slot_visible_to_user($slot, $courseid, $userid, $context)) {
            $out[] = $slot;
        }
    }
    return $out;
}

function block_googlemeet_tutorials_user_valid_email(\stdClass $user): ?string {
    if (empty($user->email) || !validate_email($user->email)) {
        return null;
    }
    return $user->email;
}

/**
 * Register current user for a slot; updates Google Calendar using host token.
 *
 * @param int $viewcourseid The course schedule from which the student is registering.
 */
function block_googlemeet_tutorials_register_user(
    \stdClass $slot,
    \stdClass $studentuser,
    \context_course $context,
    int $viewcourseid = 0
): void {
    global $DB;

    if ($viewcourseid === 0) {
        $viewcourseid = (int) $slot->courseid;
    }

    if (!has_capability('block/googlemeet_tutorials:register', $context, $studentuser->id)) {
        throw new moodle_exception('nopermission', 'block_googlemeet_tutorials');
    }
    if (!block_googlemeet_tutorials_slot_visible_to_user($slot, $viewcourseid, (int) $studentuser->id, $context)) {
        throw new moodle_exception('nopermission', 'block_googlemeet_tutorials');
    }
    $email = block_googlemeet_tutorials_user_valid_email($studentuser);
    if (!$email) {
        throw new moodle_exception('emailrequired', 'block_googlemeet_tutorials');
    }
    if ($slot->timestart < time() - 60) {
        throw new moodle_exception('pastslot', 'block_googlemeet_tutorials');
    }
    if (block_googlemeet_tutorials_user_is_registered($slot->id, $studentuser->id)) {
        return;
    }

    $count = block_googlemeet_tutorials_count_registrations($slot->id);
    if ($count >= (int) $slot->maxstudents) {
        throw new moodle_exception('registrationfailed', 'block_googlemeet_tutorials');
    }

    $hostid = (int) $slot->userid;
    $hostuser = core_user::get_user($hostid, '*', MUST_EXIST);
    $client = block_googlemeet_tutorials_get_calendar_client($hostid);
    if (!$client) {
        throw new moodle_exception('googlehostnotconnected', 'block_googlemeet_tutorials');
    }

    $tz = block_googlemeet_tutorials_user_timezone($hostuser);
    $title = format_string($slot->title);
    $intro = format_text($slot->intro, (int) $slot->introformat, ['context' => $context]);

    $max = (int) $slot->maxstudents;

    try {
        if ($max > 1) {
            if (empty($slot->googleeventid)) {
                throw new moodle_exception('registrationfailed', 'block_googlemeet_tutorials');
            }
            $event = calendar_api::add_attendee(
                $client,
                $slot->googleeventid,
                $email,
                fullname($studentuser)
            );
            block_googlemeet_tutorials_apply_google_event_to_slot($slot, $event, $tz);
            block_googlemeet_tutorials_persist_google_token($hostid, $client);
        } else {
            if (!empty($slot->googleeventid)) {
                $event = calendar_api::add_attendee(
                    $client,
                    $slot->googleeventid,
                    $email,
                    fullname($studentuser)
                );
                block_googlemeet_tutorials_apply_google_event_to_slot($slot, $event, $tz);
                block_googlemeet_tutorials_persist_google_token($hostid, $client);
            } else {
                $ge = calendar_api::create_meet_event(
                    $client,
                    $tz,
                    $title,
                    $intro,
                    (int) $slot->timestart,
                    (int) $slot->timeend,
                    [['email' => $email, 'displayname' => fullname($studentuser)]]
                );
                block_googlemeet_tutorials_persist_google_token($hostid, $client);
                $slot->googleeventid = $ge->getId();
                $slot->meeturl = $ge->getHangoutLink() ?: '';
                $slot->timemodified = time();
                $DB->update_record('block_googlemeet_tutorials_slot', $slot);
            }
        }
    } catch (\Throwable $e) {
        if ($e instanceof moodle_exception) {
            throw $e;
        }
        // Never use debugging() with Google payloads here: under Whoops/developer
        // mode it becomes a fatal user-facing notice.
        error_log('block_googlemeet_tutorials register_user: ' . $e->getMessage());
        if (calendar_api::is_rate_limit_error($e)) {
            throw new moodle_exception('googleratelimit', 'block_googlemeet_tutorials');
        }
        throw new moodle_exception('registrationfailed', 'block_googlemeet_tutorials');
    }

    $reg = (object) [
        'slotid' => $slot->id,
        'userid' => $studentuser->id,
        'registrationcourseid' => $viewcourseid,
        'timecreated' => time(),
    ];
    $DB->insert_record('block_googlemeet_tutorials_reg', $reg);
}

/**
 * Update a Moodle slot's times/Meet URL from a Google Calendar event when they differ.
 *
 * @param \stdClass $slot
 * @param \Google_Service_Calendar_Event $event
 * @param string $fallbacktimezone
 * @return bool True when the slot row was updated
 */
function block_googlemeet_tutorials_apply_google_event_to_slot(
    \stdClass $slot,
    $event,
    string $fallbacktimezone
): bool {
    global $DB;

    $data = calendar_api::event_time_data($event, $fallbacktimezone);
    if (!$data) {
        return false;
    }

    $changed = false;
    if ((int) $slot->timestart !== (int) $data['timestart']) {
        $slot->timestart = (int) $data['timestart'];
        $changed = true;
    }
    if ((int) $slot->timeend !== (int) $data['timeend']) {
        $slot->timeend = (int) $data['timeend'];
        $changed = true;
    }
    if ($data['meeturl'] !== '' && (string) $slot->meeturl !== $data['meeturl']) {
        $slot->meeturl = $data['meeturl'];
        $changed = true;
    }
    if ($changed) {
        $slot->timemodified = time();
        $DB->update_record('block_googlemeet_tutorials_slot', $slot);
    }
    return $changed;
}

/**
 * Pull current times/Meet URL for one slot from Google Calendar.
 *
 * @param \stdClass $slot
 * @param \Google_Client $client
 * @param string $fallbacktimezone
 * @return bool
 */
function block_googlemeet_tutorials_sync_slot_from_google(
    \stdClass $slot,
    $client,
    string $fallbacktimezone
): bool {
    if (empty($slot->googleeventid)) {
        return false;
    }
    $event = calendar_api::get_event($client, $slot->googleeventid);
    return block_googlemeet_tutorials_apply_google_event_to_slot($slot, $event, $fallbacktimezone);
}

/**
 * Sync all of a host's Google-linked slots in a course from Calendar.
 *
 * @param int $courseid
 * @param int $hostuserid
 * @return int Number of slots updated
 */
function block_googlemeet_tutorials_sync_host_slots_from_google(int $courseid, int $hostuserid): int {
    global $DB;

    $client = block_googlemeet_tutorials_get_calendar_client($hostuserid);
    if (!$client) {
        throw new moodle_exception('googlenotconnected', 'block_googlemeet_tutorials');
    }
    $hostuser = core_user::get_user($hostuserid, '*', MUST_EXIST);
    $tz = block_googlemeet_tutorials_user_timezone($hostuser);

    $slots = $DB->get_records_select(
        'block_googlemeet_tutorials_slot',
        'userid = :u AND status = 1 AND googleeventid <> :empty
         AND (courseid = :c OR scope = 1)',
        ['u' => $hostuserid, 'c' => $courseid, 'empty' => ''],
        'timestart ASC'
    );

    $updated = 0;
    foreach ($slots as $slot) {
        // Site-wide slots: only sync when host manages this course.
        if ((int) ($slot->scope ?? 0) === 1 && (int) $slot->courseid !== $courseid) {
            if (!block_googlemeet_tutorials_host_manages_in_course($hostuserid, $courseid)) {
                continue;
            }
        }
        try {
            if (block_googlemeet_tutorials_sync_slot_from_google($slot, $client, $tz)) {
                $updated++;
            }
        } catch (\Throwable $e) {
            if (calendar_api::is_rate_limit_error($e)) {
                throw new moodle_exception('googleratelimit', 'block_googlemeet_tutorials');
            }
            error_log('block_googlemeet_tutorials sync slot ' . $slot->id . ': ' . $e->getMessage());
        }
        // Small pause to reduce Calendar API burst usage.
        usleep(200000);
    }
    block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
    return $updated;
}

function block_googlemeet_tutorials_unregister_user(\stdClass $slot, \stdClass $studentuser): void {
    global $DB;

    if (!block_googlemeet_tutorials_user_is_registered($slot->id, $studentuser->id)) {
        return;
    }

    $hostid = (int) $slot->userid;
    $client = block_googlemeet_tutorials_get_calendar_client($hostid);
    $email = block_googlemeet_tutorials_user_valid_email($studentuser);

    if ($client && $email && !empty($slot->googleeventid)) {
        $max = (int) $slot->maxstudents;
        try {
            if ($max > 1) {
                calendar_api::remove_attendee($client, $slot->googleeventid, $email);
                block_googlemeet_tutorials_persist_google_token($hostid, $client);
            } else {
                calendar_api::delete_event($client, $slot->googleeventid);
                block_googlemeet_tutorials_persist_google_token($hostid, $client);
                $slot->googleeventid = '';
                $slot->meeturl = '';
                $slot->timemodified = time();
                $DB->update_record('block_googlemeet_tutorials_slot', $slot);
            }
        } catch (\Throwable $e) {
            throw new moodle_exception('registrationfailed', 'block_googlemeet_tutorials');
        }
    }

    $DB->delete_records('block_googlemeet_tutorials_reg', ['slotid' => $slot->id, 'userid' => $studentuser->id]);
}

function block_googlemeet_tutorials_delete_slot(\stdClass $slot): void {
    global $DB;

    if (!empty($slot->googleeventid)) {
        $client = block_googlemeet_tutorials_get_calendar_client((int) $slot->userid);
        if ($client) {
            try {
                calendar_api::delete_event($client, $slot->googleeventid);
                block_googlemeet_tutorials_persist_google_token((int) $slot->userid, $client);
            } catch (\Throwable $e) {
                // Continue deleting Moodle rows even if Google delete fails.
            }
        }
    }
    $DB->delete_records('block_googlemeet_tutorials_reg', ['slotid' => $slot->id]);
    $seriesid = !empty($slot->seriesid) ? (int) $slot->seriesid : 0;
    $DB->delete_records('block_googlemeet_tutorials_slot', ['id' => $slot->id]);
    if ($seriesid && !$DB->record_exists('block_googlemeet_tutorials_slot', ['seriesid' => $seriesid])) {
        $DB->delete_records('block_googlemeet_tutorials_series', ['id' => $seriesid]);
    }
}

/**
 * @return int new slot id
 */
function block_googlemeet_tutorials_insert_slot(
    int $courseid,
    int $hostuserid,
    string $title,
    string $intro,
    int $introformat,
    int $timestart,
    int $timeend,
    int $maxstudents,
    int $scope = 0,
    int $usegroups = 1
): int {
    global $DB;

    if ($timestart >= $timeend) {
        throw new moodle_exception('invaliddata', 'error');
    }
    if ($maxstudents < 1) {
        throw new moodle_exception('invaliddata', 'error');
    }

    $hostuser = core_user::get_user($hostuserid, '*', MUST_EXIST);
    $tz = block_googlemeet_tutorials_user_timezone($hostuser);

    $row = (object) [
        'courseid' => $courseid,
        'userid' => $hostuserid,
        'title' => $title,
        'intro' => $intro,
        'introformat' => $introformat,
        'timestart' => $timestart,
        'timeend' => $timeend,
        'maxstudents' => $maxstudents,
        'googleeventid' => '',
        'meeturl' => '',
        'status' => 1,
        'scope' => $scope,
        'usegroups' => $usegroups ? 1 : 0,
        'timecreated' => time(),
        'timemodified' => time(),
    ];

    if ($maxstudents > 1) {
        $client = block_googlemeet_tutorials_get_calendar_client($hostuserid);
        if (!$client) {
            throw new moodle_exception('googlenotconnected', 'block_googlemeet_tutorials');
        }
        $ge = calendar_api::create_meet_event(
            $client,
            $tz,
            $title,
            $intro,
            $timestart,
            $timeend,
            []
        );
        block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
        $row->googleeventid = $ge->getId();
        $row->meeturl = $ge->getHangoutLink() ?: '';
    }

    return (int) $DB->insert_record('block_googlemeet_tutorials_slot', $row);
}

function block_googlemeet_tutorials_update_slot(
    \stdClass $old,
    string $title,
    string $intro,
    int $introformat,
    int $timestart,
    int $timeend,
    int $maxstudents,
    int $usegroups = 1
): void {
    global $DB;

    if ($timestart >= $timeend) {
        throw new moodle_exception('invaliddata', 'error');
    }

    $slotid = (int) $old->id;
    $regcount = block_googlemeet_tutorials_count_registrations($slotid);
    if ($maxstudents < $regcount) {
        throw new moodle_exception('capacityreduced', 'block_googlemeet_tutorials');
    }
    if ($regcount > 0) {
        if ((int) $old->maxstudents !== $maxstudents) {
            throw new moodle_exception('lockedfields', 'block_googlemeet_tutorials');
        }
        if ((int) $old->timestart !== $timestart || (int) $old->timeend !== $timeend) {
            throw new moodle_exception('lockedfields', 'block_googlemeet_tutorials');
        }
    }

    $hostuserid = (int) $old->userid;
    $hostuser = core_user::get_user($hostuserid, '*', MUST_EXIST);
    $tz = block_googlemeet_tutorials_user_timezone($hostuser);

    $prevstart = (int) $old->timestart;
    $prevend = (int) $old->timeend;
    $oldmax = (int) $old->maxstudents;
    $hadgoogle = !empty($old->googleeventid);
    $createdgoogle = false;

    if ($regcount === 0) {
        if ($oldmax > 1 && $maxstudents === 1 && $hadgoogle) {
            $client = block_googlemeet_tutorials_get_calendar_client($hostuserid);
            if ($client) {
                calendar_api::delete_event($client, $old->googleeventid);
                block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
            }
            $old->googleeventid = '';
            $old->meeturl = '';
        } else if ($oldmax === 1 && $maxstudents > 1 && !$hadgoogle) {
            $client = block_googlemeet_tutorials_get_calendar_client($hostuserid);
            if (!$client) {
                throw new moodle_exception('googlenotconnected', 'block_googlemeet_tutorials');
            }
            $ge = calendar_api::create_meet_event(
                $client,
                $tz,
                $title,
                $intro,
                $timestart,
                $timeend,
                []
            );
            block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
            $old->googleeventid = $ge->getId();
            $old->meeturl = $ge->getHangoutLink() ?: '';
            $createdgoogle = true;
        }
    }

    $old->title = $title;
    $old->intro = $intro;
    $old->introformat = $introformat;
    $old->timestart = $timestart;
    $old->timeend = $timeend;
    $old->maxstudents = $maxstudents;
    $old->usegroups = $usegroups ? 1 : 0;
    $old->timemodified = time();

    $client = block_googlemeet_tutorials_get_calendar_client($hostuserid);
    if ($client && !empty($old->googleeventid) && !$createdgoogle) {
        if ($regcount > 0) {
            calendar_api::patch_event_text($client, $old->googleeventid, $title, $intro);
        } else {
            $timeschanged = ($prevstart !== $timestart || $prevend !== $timeend);
            if ($timeschanged) {
                calendar_api::patch_event_times(
                    $client,
                    $old->googleeventid,
                    $tz,
                    $title,
                    $intro,
                    $timestart,
                    $timeend
                );
            } else {
                calendar_api::patch_event_text($client, $old->googleeventid, $title, $intro);
            }
        }
        block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
    }

    $DB->update_record('block_googlemeet_tutorials_slot', $old);
}

/**
 * Slots belonging to a series.
 *
 * @param int $seriesid
 * @return array
 */
function block_googlemeet_tutorials_get_series_slots(int $seriesid): array {
    global $DB;
    return $DB->get_records('block_googlemeet_tutorials_slot', ['seriesid' => $seriesid], 'instanceindex ASC');
}

/**
 * @param int $seriesid
 * @return bool
 */
function block_googlemeet_tutorials_series_has_registrations(int $seriesid): bool {
    global $DB;
    $slots = block_googlemeet_tutorials_get_series_slots($seriesid);
    foreach ($slots as $slot) {
        if (block_googlemeet_tutorials_count_registrations((int) $slot->id) > 0) {
            return true;
        }
    }
    return false;
}

/**
 * Match Google Calendar instances to expanded occurrences by start time.
 *
 * @param array $instances From calendar_api::list_instances
 * @param array $occurrences From recurrence::expand_occurrences
 * @return array<int,array{id:string,meeturl:string}> keyed by occurrence index
 */
function block_googlemeet_tutorials_map_instances_to_occurrences(array $instances, array $occurrences): array {
    $map = [];
    $used = [];
    foreach ($occurrences as $idx => $occ) {
        $best = null;
        $bestdiff = 120;
        foreach ($instances as $i => $inst) {
            if (isset($used[$i])) {
                continue;
            }
            $diff = abs((int) $inst['timestart'] - (int) $occ['timestart']);
            if ($diff < $bestdiff) {
                $bestdiff = $diff;
                $best = $i;
            }
        }
        if ($best !== null) {
            $used[$best] = true;
            $map[$idx] = [
                'id' => $instances[$best]['id'],
                'meeturl' => $instances[$best]['meeturl'],
            ];
        }
    }
    return $map;
}

/**
 * Build recurrence config array from form data.
 *
 * @param stdClass $data Form data
 * @param string $timezone
 * @return array
 */
function block_googlemeet_tutorials_recurrence_config_from_form(stdClass $data, string $timezone): array {
    $byday = '';
    if (!empty($data->recurrence_byday)) {
        if (is_array($data->recurrence_byday)) {
            $byday = implode(',', $data->recurrence_byday);
        } else {
            $byday = (string) $data->recurrence_byday;
        }
    }
    return [
        'preset' => $data->repeat_preset ?? recurrence::PRESET_WEEKLY,
        'timestart' => (int) $data->timestart,
        'recuruntil' => recurrence::normalise_recuruntil($data->recuruntil, $timezone),
        'timezone' => $timezone,
        'recurrence_interval' => (int) ($data->recurrence_interval ?? 1),
        'recurrence_frequency' => $data->recurrence_frequency ?? 'WEEKLY',
        'recurrence_byday' => $byday,
    ];
}

/**
 * Insert slot rows for a recurring series.
 *
 * @return int series id
 */
function block_googlemeet_tutorials_insert_series(
    int $courseid,
    int $hostuserid,
    string $title,
    string $intro,
    int $introformat,
    int $timestart,
    int $timeend,
    int $maxstudents,
    array $recurrenceconfig,
    int $scope = 0,
    int $usegroups = 1
): int {
    global $DB;

    if ($timestart >= $timeend) {
        throw new moodle_exception('invaliddata', 'error');
    }
    if ($maxstudents < 1) {
        throw new moodle_exception('invaliddata', 'error');
    }

    $hostuser = core_user::get_user($hostuserid, '*', MUST_EXIST);
    $tz = block_googlemeet_tutorials_user_timezone($hostuser);
    $duration = $timeend - $timestart;
    $rrule = recurrence::build_rrule($recurrenceconfig);
    $occurrences = recurrence::expand_occurrences($timestart, $duration, $recurrenceconfig, $tz);
    if (count($occurrences) < 2) {
        throw new moodle_exception('recurrenceminoccurrences', 'block_googlemeet_tutorials');
    }
    if (count($occurrences) >= recurrence::MAX_OCCURRENCES) {
        throw new moodle_exception('recurrencemaxoccurrences', 'block_googlemeet_tutorials', '', recurrence::MAX_OCCURRENCES);
    }

    $now = time();
    $series = (object) [
        'courseid' => $courseid,
        'userid' => $hostuserid,
        'title' => $title,
        'intro' => $intro,
        'introformat' => $introformat,
        'timestart' => $timestart,
        'timeend' => $timeend,
        'maxstudents' => $maxstudents,
        'recurrence_preset' => $recurrenceconfig['preset'],
        'recurrence_interval' => (int) ($recurrenceconfig['recurrence_interval'] ?? 1),
        'recurrence_frequency' => $recurrenceconfig['recurrence_frequency'] ?? null,
        'recurrence_byday' => $recurrenceconfig['recurrence_byday'] ?? null,
        'recuruntil' => (int) $recurrenceconfig['recuruntil'],
        'rrule' => $rrule,
        'google_recurring_id' => '',
        'scope' => $scope,
        'usegroups' => $usegroups ? 1 : 0,
        'timecreated' => $now,
        'timemodified' => $now,
    ];
    $seriesid = (int) $DB->insert_record('block_googlemeet_tutorials_series', $series);

    $instancemap = [];
    if ($maxstudents > 1) {
        $client = block_googlemeet_tutorials_get_calendar_client($hostuserid);
        if (!$client) {
            $DB->delete_records('block_googlemeet_tutorials_series', ['id' => $seriesid]);
            throw new moodle_exception('googlenotconnected', 'block_googlemeet_tutorials');
        }
        $ge = calendar_api::create_recurring_meet_event(
            $client,
            $tz,
            $title,
            $intro,
            $timestart,
            $timeend,
            $rrule
        );
        block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
        $series->id = $seriesid;
        $series->google_recurring_id = $ge->getId();
        $DB->update_record('block_googlemeet_tutorials_series', $series);

        $instances = calendar_api::list_instances($client, $ge->getId());
        $instancemap = block_googlemeet_tutorials_map_instances_to_occurrences($instances, $occurrences);
    }

    foreach ($occurrences as $idx => $occ) {
        $row = (object) [
            'courseid' => $courseid,
            'userid' => $hostuserid,
            'title' => $title,
            'intro' => $intro,
            'introformat' => $introformat,
            'timestart' => $occ['timestart'],
            'timeend' => $occ['timeend'],
            'maxstudents' => $maxstudents,
            'googleeventid' => $instancemap[$idx]['id'] ?? '',
            'meeturl' => $instancemap[$idx]['meeturl'] ?? '',
            'status' => 1,
            'scope' => $scope,
            'usegroups' => $usegroups ? 1 : 0,
            'seriesid' => $seriesid,
            'instanceindex' => $idx,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('block_googlemeet_tutorials_slot', $row);
    }

    return $seriesid;
}

/**
 * Update an entire recurring series.
 */
function block_googlemeet_tutorials_update_series(
    \stdClass $series,
    string $title,
    string $intro,
    int $introformat,
    int $timestart,
    int $timeend,
    int $maxstudents,
    int $usegroups = 1
): void {
    global $DB;

    if ($timestart >= $timeend) {
        throw new moodle_exception('invaliddata', 'error');
    }

    $seriesid = (int) $series->id;
    $slots = block_googlemeet_tutorials_get_series_slots($seriesid);
    if (!$slots) {
        throw new moodle_exception('invalidslot', 'block_googlemeet_tutorials');
    }

    $totalregs = 0;
    foreach ($slots as $slot) {
        $totalregs += block_googlemeet_tutorials_count_registrations((int) $slot->id);
    }
    if ($maxstudents < 1) {
        throw new moodle_exception('invaliddata', 'error');
    }
    if ($totalregs > 0 && (int) $series->maxstudents !== $maxstudents) {
        throw new moodle_exception('lockedfields', 'block_googlemeet_tutorials');
    }

    $timeschanged = ((int) $series->timestart !== $timestart || (int) $series->timeend !== $timeend);
    if ($timeschanged && $totalregs > 0) {
        throw new moodle_exception('lockedfields', 'block_googlemeet_tutorials');
    }

    $hostuserid = (int) $series->userid;
    $hostuser = core_user::get_user($hostuserid, '*', MUST_EXIST);
    $tz = block_googlemeet_tutorials_user_timezone($hostuser);

    if ($timeschanged && $totalregs === 0) {
        block_googlemeet_tutorials_rebuild_series_occurrences(
            $series,
            $title,
            $intro,
            $introformat,
            $timestart,
            $timeend,
            $maxstudents,
            $usegroups
        );
        return;
    }

    $now = time();
    $series->title = $title;
    $series->intro = $intro;
    $series->introformat = $introformat;
    $series->maxstudents = $maxstudents;
    $series->usegroups = $usegroups ? 1 : 0;
    $series->timemodified = $now;
    $DB->update_record('block_googlemeet_tutorials_series', $series);

    $client = block_googlemeet_tutorials_get_calendar_client($hostuserid);
    if ($client && !empty($series->google_recurring_id)) {
        calendar_api::patch_recurring_master(
            $client,
            $series->google_recurring_id,
            $tz,
            $title,
            $intro
        );
        block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
    }

    foreach ($slots as $slot) {
        $regcount = block_googlemeet_tutorials_count_registrations((int) $slot->id);
        $slot->title = $title;
        $slot->intro = $intro;
        $slot->introformat = $introformat;
        if ($regcount === 0) {
            $slot->maxstudents = $maxstudents;
        }
        $slot->usegroups = $usegroups ? 1 : 0;
        $slot->timemodified = $now;
        $DB->update_record('block_googlemeet_tutorials_slot', $slot);

        if ($client && !empty($slot->googleeventid)) {
            if ($regcount > 0) {
                calendar_api::patch_event_text($client, $slot->googleeventid, $title, $intro);
            } else {
                calendar_api::patch_event_text($client, $slot->googleeventid, $title, $intro);
            }
        }
    }
    if ($client) {
        block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
    }
}

/**
 * Rebuild all slot rows when series times change with no registrations.
 */
function block_googlemeet_tutorials_rebuild_series_occurrences(
    \stdClass $series,
    string $title,
    string $intro,
    int $introformat,
    int $timestart,
    int $timeend,
    int $maxstudents,
    int $usegroups = 1
): void {
    global $DB;

    $seriesid = (int) $series->id;
    $hostuserid = (int) $series->userid;
    $hostuser = core_user::get_user($hostuserid, '*', MUST_EXIST);
    $tz = block_googlemeet_tutorials_user_timezone($hostuser);

    $client = block_googlemeet_tutorials_get_calendar_client($hostuserid);
    if (!empty($series->google_recurring_id) && $client) {
        try {
            calendar_api::delete_recurring_master($client, $series->google_recurring_id);
            block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
        } catch (\Throwable $e) {
            // Continue rebuild.
        }
    }

    foreach (block_googlemeet_tutorials_get_series_slots($seriesid) as $slot) {
        $DB->delete_records('block_googlemeet_tutorials_reg', ['slotid' => $slot->id]);
        $DB->delete_records('block_googlemeet_tutorials_slot', ['id' => $slot->id]);
    }

    $recurrenceconfig = [
        'preset' => $series->recurrence_preset,
        'timestart' => $timestart,
        'recuruntil' => (int) $series->recuruntil,
        'timezone' => $tz,
        'recurrence_interval' => (int) $series->recurrence_interval,
        'recurrence_frequency' => $series->recurrence_frequency,
        'recurrence_byday' => $series->recurrence_byday,
    ];
    $duration = $timeend - $timestart;
    $rrule = recurrence::build_rrule($recurrenceconfig);
    $occurrences = recurrence::expand_occurrences($timestart, $duration, $recurrenceconfig, $tz);

    $series->title = $title;
    $series->intro = $intro;
    $series->introformat = $introformat;
    $series->timestart = $timestart;
    $series->timeend = $timeend;
    $series->maxstudents = $maxstudents;
    $series->usegroups = $usegroups ? 1 : 0;
    $series->rrule = $rrule;
    $series->google_recurring_id = '';
    $series->timemodified = time();
    $DB->update_record('block_googlemeet_tutorials_series', $series);

    $instancemap = [];
    if ($maxstudents > 1) {
        if (!$client) {
            throw new moodle_exception('googlenotconnected', 'block_googlemeet_tutorials');
        }
        $ge = calendar_api::create_recurring_meet_event(
            $client,
            $tz,
            $title,
            $intro,
            $timestart,
            $timeend,
            $rrule
        );
        block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
        $series->google_recurring_id = $ge->getId();
        $DB->update_record('block_googlemeet_tutorials_series', $series);
        $instances = calendar_api::list_instances($client, $ge->getId());
        $instancemap = block_googlemeet_tutorials_map_instances_to_occurrences($instances, $occurrences);
    }

    $now = time();
    foreach ($occurrences as $idx => $occ) {
        $row = (object) [
            'courseid' => (int) $series->courseid,
            'userid' => $hostuserid,
            'title' => $title,
            'intro' => $intro,
            'introformat' => $introformat,
            'timestart' => $occ['timestart'],
            'timeend' => $occ['timeend'],
            'maxstudents' => $maxstudents,
            'googleeventid' => $instancemap[$idx]['id'] ?? '',
            'meeturl' => $instancemap[$idx]['meeturl'] ?? '',
            'status' => 1,
            'scope' => (int) ($series->scope ?? 0),
            'usegroups' => $usegroups ? 1 : 0,
            'seriesid' => $seriesid,
            'instanceindex' => $idx,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('block_googlemeet_tutorials_slot', $row);
    }
}

/**
 * Delete an entire recurring series and all its slots.
 */
function block_googlemeet_tutorials_delete_series(\stdClass $series): void {
    global $DB;

    if (block_googlemeet_tutorials_series_has_registrations((int) $series->id)) {
        throw new moodle_exception('deleteseriesblocked', 'block_googlemeet_tutorials');
    }

    $hostuserid = (int) $series->userid;
    if (!empty($series->google_recurring_id)) {
        $client = block_googlemeet_tutorials_get_calendar_client($hostuserid);
        if ($client) {
            try {
                calendar_api::delete_recurring_master($client, $series->google_recurring_id);
                block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
            } catch (\Throwable $e) {
                // Continue deleting Moodle rows.
            }
        }
    } else {
        foreach (block_googlemeet_tutorials_get_series_slots((int) $series->id) as $slot) {
            if (!empty($slot->googleeventid)) {
                $client = block_googlemeet_tutorials_get_calendar_client($hostuserid);
                if ($client) {
                    try {
                        calendar_api::delete_event($client, $slot->googleeventid);
                        block_googlemeet_tutorials_persist_google_token($hostuserid, $client);
                    } catch (\Throwable $e) {
                        // Continue.
                    }
                }
            }
        }
    }

    foreach (block_googlemeet_tutorials_get_series_slots((int) $series->id) as $slot) {
        $DB->delete_records('block_googlemeet_tutorials_reg', ['slotid' => $slot->id]);
        $DB->delete_records('block_googlemeet_tutorials_slot', ['id' => $slot->id]);
    }
    $DB->delete_records('block_googlemeet_tutorials_series', ['id' => $series->id]);
}
