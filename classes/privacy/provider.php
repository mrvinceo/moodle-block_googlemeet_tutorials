<?php
// This file is part of Moodle - http://moodle.org/

namespace block_googlemeet_tutorials\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('block_googlemeet_tutorials_slot', [
            'userid' => 'privacy:metadata:slot:userid',
            'title' => 'privacy:metadata:slot:title',
            'timestart' => 'privacy:metadata:slot:timestart',
            'timeend' => 'privacy:metadata:slot:timeend',
            'scope' => 'privacy:metadata:slot:scope',
            'usegroups' => 'privacy:metadata:slot:usegroups',
        ], 'privacy:metadata:slots');

        $collection->add_database_table('block_googlemeet_tutorials_series', [
            'userid' => 'privacy:metadata:series:userid',
            'title' => 'privacy:metadata:series:title',
            'scope' => 'privacy:metadata:series:scope',
            'usegroups' => 'privacy:metadata:series:usegroups',
        ], 'privacy:metadata:series');

        $collection->add_database_table('block_googlemeet_tutorials_reg', [
            'userid' => 'privacy:metadata:reg:userid',
            'slotid' => 'privacy:metadata:reg:slotid',
            'registrationcourseid' => 'privacy:metadata:reg:registrationcourseid',
        ], 'privacy:metadata:regs');

        $collection->add_database_table('block_googlemeet_tutorials_token', [
            'user_id' => 'privacy:metadata:token:userid',
            'user_email' => 'privacy:metadata:token:email',
        ], 'privacy:metadata:tokens');

        $collection->add_external_location_link('google_calendar', 'privacy:metadata:google_calendar', [
            'email' => 'privacy:metadata:token:email',
        ]);

        return $collection;
    }

    /**
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();

        // Include origin courses of hosted slots (both course-scoped and site-wide),
        // plus the registrationcourseid for registrations made by this user.
        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {course} c ON c.id = ctx.instanceid
                 WHERE ctx.contextlevel = :courselevel
                   AND (
                        EXISTS (
                            SELECT 1 FROM {block_googlemeet_tutorials_slot} s
                             WHERE s.courseid = c.id AND s.userid = :hostid
                        )
                        OR EXISTS (
                            SELECT 1 FROM {block_googlemeet_tutorials_reg} r
                             WHERE r.userid = :regid
                               AND (
                                    r.registrationcourseid = c.id
                                    OR (r.registrationcourseid = 0 AND EXISTS (
                                        SELECT 1 FROM {block_googlemeet_tutorials_slot} s2
                                         WHERE s2.id = r.slotid AND s2.courseid = c.id
                                    ))
                               )
                        )
                   )";

        $params = [
            'courselevel' => CONTEXT_COURSE,
            'hostid' => $userid,
            'regid' => $userid,
        ];
        $contextlist->add_from_sql($sql, $params);

        if ($DB->record_exists('block_googlemeet_tutorials_token', ['user_id' => $userid])) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                $token = $DB->get_record('block_googlemeet_tutorials_token', ['user_id' => $userid]);
                if ($token) {
                    writer::with_context($context)->export_data(
                        [get_string('privacy:path:tokens', 'block_googlemeet_tutorials')],
                        (object) [
                            'user_email' => $token->user_email,
                            'timecreated' => $token->timecreated,
                            'has_token' => !empty($token->token),
                        ]
                    );
                }
                continue;
            }

            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $courseid = $context->instanceid;

            // Hosted slots (origin course = this course).
            $slots = $DB->get_records_select('block_googlemeet_tutorials_slot', 'courseid = ? AND userid = ?', [$courseid, $userid]);
            foreach ($slots as $s) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:slots', 'block_googlemeet_tutorials'), $s->id],
                    (object) [
                        'title' => $s->title,
                        'timestart' => $s->timestart,
                        'timeend' => $s->timeend,
                        'maxstudents' => $s->maxstudents,
                        'scope' => (int) ($s->scope ?? 0),
                        'usegroups' => (int) ($s->usegroups ?? 1),
                    ]
                );
            }

            // Registrations where registrationcourseid = this course (or legacy: slot's origin course).
            $sql = "SELECT r.*
                      FROM {block_googlemeet_tutorials_reg} r
                      JOIN {block_googlemeet_tutorials_slot} s ON s.id = r.slotid
                     WHERE r.userid = ?
                       AND (
                            r.registrationcourseid = ?
                            OR (r.registrationcourseid = 0 AND s.courseid = ?)
                       )";
            $regs = $DB->get_records_sql($sql, [$userid, $courseid, $courseid]);
            foreach ($regs as $r) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:regs', 'block_googlemeet_tutorials'), $r->id],
                    (object) [
                        'slotid' => $r->slotid,
                        'registrationcourseid' => $r->registrationcourseid,
                        'timecreated' => $r->timecreated,
                    ]
                );
            }
        }
    }

    /**
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->delete_records('block_googlemeet_tutorials_token');
            return;
        }
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $courseid = $context->instanceid;
        $slotids = array_keys($DB->get_records('block_googlemeet_tutorials_slot', ['courseid' => $courseid], 'id'));
        if ($slotids) {
            list($insql, $params) = $DB->get_in_or_equal($slotids);
            $DB->delete_records_select('block_googlemeet_tutorials_reg', "slotid $insql", $params);
            $DB->delete_records('block_googlemeet_tutorials_slot', ['courseid' => $courseid]);
        }
        // Also delete registrations recorded as coming from this course (site-wide slots).
        $DB->delete_records('block_googlemeet_tutorials_reg', ['registrationcourseid' => $courseid]);
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                $DB->delete_records('block_googlemeet_tutorials_token', ['user_id' => $userid]);
                continue;
            }
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $courseid = $context->instanceid;

            // Delete registrations by this user originating from this course.
            $DB->delete_records_select(
                'block_googlemeet_tutorials_reg',
                'userid = :u AND (registrationcourseid = :c OR (registrationcourseid = 0 AND slotid IN (
                    SELECT id FROM {block_googlemeet_tutorials_slot} WHERE courseid = :c2
                )))',
                ['u' => $userid, 'c' => $courseid, 'c2' => $courseid]
            );

            // Delete hosted slots in this course (and their registrations).
            $hostslots = $DB->get_records('block_googlemeet_tutorials_slot', ['courseid' => $courseid, 'userid' => $userid], 'id');
            foreach ($hostslots as $h) {
                $DB->delete_records('block_googlemeet_tutorials_reg', ['slotid' => $h->id]);
            }
            $DB->delete_records('block_googlemeet_tutorials_slot', ['courseid' => $courseid, 'userid' => $userid]);
        }
    }

    /**
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $sql = "SELECT t.user_id AS userid FROM {block_googlemeet_tutorials_token} t";
            $userlist->add_from_sql('userid', $sql, []);
            return;
        }
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $courseid = $context->instanceid;
        $sql = "SELECT s.userid AS userid FROM {block_googlemeet_tutorials_slot} s WHERE s.courseid = :c1
                UNION
                SELECT r.userid FROM {block_googlemeet_tutorials_reg} r
                  JOIN {block_googlemeet_tutorials_slot} s2 ON s2.id = r.slotid
                 WHERE s2.courseid = :c2
                UNION
                SELECT r2.userid FROM {block_googlemeet_tutorials_reg} r2
                 WHERE r2.registrationcourseid = :c3";
        $userlist->add_from_sql('userid', $sql, [
            'c1' => $courseid,
            'c2' => $courseid,
            'c3' => $courseid,
        ]);
    }

    /**
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        $context = $userlist->get_context();
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->delete_records_select('block_googlemeet_tutorials_token', "user_id $insql", $params);
            return;
        }
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $params['courseid'] = $context->instanceid;
        $DB->delete_records_select(
            'block_googlemeet_tutorials_reg',
            "userid $insql AND (registrationcourseid = :courseid OR (registrationcourseid = 0 AND slotid IN (
                SELECT id FROM {block_googlemeet_tutorials_slot} WHERE courseid = :courseid2
            )))",
            $params + ['courseid2' => $context->instanceid]
        );

        $slots = $DB->get_records_select(
            'block_googlemeet_tutorials_slot',
            "courseid = :courseid AND userid $insql",
            $params
        );
        foreach ($slots as $slot) {
            $DB->delete_records('block_googlemeet_tutorials_reg', ['slotid' => $slot->id]);
            $DB->delete_records('block_googlemeet_tutorials_slot', ['id' => $slot->id]);
        }
    }
}
