<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for block_googlemeet_tutorials.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_block_googlemeet_tutorials_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026051400) {
        $table = new xmldb_table('block_googlemeet_tut_series');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('intro', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('introformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timeend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('maxstudents', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('recurrence_preset', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('recurrence_interval', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('recurrence_frequency', XMLDB_TYPE_CHAR, '10', null, null, null, null);
        $table->add_field('recurrence_byday', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        $table->add_field('recuruntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('rrule', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('google_recurring_id', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('courseid-userid', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $slottable = new xmldb_table('block_googlemeet_tut_slot');
        $field = new xmldb_field('seriesid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'status');
        if (!$dbman->field_exists($slottable, $field)) {
            $dbman->add_field($slottable, $field);
        }
        $field = new xmldb_field('instanceindex', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'seriesid');
        if (!$dbman->field_exists($slottable, $field)) {
            $dbman->add_field($slottable, $field);
        }
        $index = new xmldb_index('seriesid-instanceindex', XMLDB_INDEX_NOTUNIQUE, ['seriesid', 'instanceindex']);
        if (!$dbman->index_exists($slottable, $index)) {
            $dbman->add_index($slottable, $index);
        }

        upgrade_block_savepoint(true, 2026051400, 'googlemeet_tutorials');
    }

    if ($oldversion < 2026051500) {
        // Add scope to slot table (0=course, 1=site-wide).
        $slottable = new xmldb_table('block_googlemeet_tut_slot');
        $field = new xmldb_field('scope', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'status');
        if (!$dbman->field_exists($slottable, $field)) {
            $dbman->add_field($slottable, $field);
        }
        $index = new xmldb_index('scope-timestart', XMLDB_INDEX_NOTUNIQUE, ['scope', 'timestart']);
        if (!$dbman->index_exists($slottable, $index)) {
            $dbman->add_index($slottable, $index);
        }
        $index = new xmldb_index('scope-userid', XMLDB_INDEX_NOTUNIQUE, ['scope', 'userid']);
        if (!$dbman->index_exists($slottable, $index)) {
            $dbman->add_index($slottable, $index);
        }

        // Add scope to series table.
        $seriestable = new xmldb_table('block_googlemeet_tut_series');
        $field = new xmldb_field('scope', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'google_recurring_id');
        if (!$dbman->field_exists($seriestable, $field)) {
            $dbman->add_field($seriestable, $field);
        }

        // Add registrationcourseid to registrations.
        $regtable = new xmldb_table('block_googlemeet_tut_reg');
        $field = new xmldb_field('registrationcourseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'userid');
        if (!$dbman->field_exists($regtable, $field)) {
            $dbman->add_field($regtable, $field);
        }

        // Backfill registrationcourseid from the slot's courseid for existing rows.
        $DB->execute("UPDATE {block_googlemeet_tut_reg} r
                         JOIN {block_googlemeet_tut_slot} s ON s.id = r.slotid
                        SET r.registrationcourseid = s.courseid
                      WHERE r.registrationcourseid = 0");

        upgrade_block_savepoint(true, 2026051500, 'googlemeet_tutorials');
    }

    if ($oldversion < 2026091400) {
        // Own Google OAuth token storage (previously shared with block_googlemeet_events).
        $table = new xmldb_table('block_googlemeet_tut_token');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('user_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('token', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('user_email', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('user_id-uix', XMLDB_INDEX_UNIQUE, ['user_id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Copy OAuth credentials from events if tutorials settings are empty.
        $events = get_config('block_googlemeet_events');
        foreach (['apikey', 'clientid', 'clientsecret'] as $name) {
            $current = get_config('block_googlemeet_tutorials', $name);
            if (($current === false || $current === null || $current === '')
                    && !empty($events->$name)) {
                set_config($name, $events->$name, 'block_googlemeet_tutorials');
            }
        }

        // Migrate existing host tokens from the shared events table when present.
        $oldtable = new xmldb_table('block_googlemeet_token');
        if ($dbman->table_exists($oldtable)) {
            $existing = $DB->get_records('block_googlemeet_tut_token', null, '', 'user_id');
            $rows = $DB->get_records('block_googlemeet_token');
            foreach ($rows as $row) {
                $userid = (int) $row->user_id;
                if ($userid < 1 || isset($existing[$userid])) {
                    continue;
                }
                if (empty($row->token) || $row->token === 'null') {
                    continue;
                }
                $DB->insert_record('block_googlemeet_tut_token', (object) [
                    'user_id' => $userid,
                    'token' => $row->token,
                    'user_email' => $row->user_email ?? null,
                    'timecreated' => !empty($row->timecreated) ? (int) $row->timecreated : time(),
                ]);
            }
        }

        upgrade_block_savepoint(true, 2026091400, 'googlemeet_tutorials');
    }

    return true;
}
