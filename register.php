<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/locallib.php');

$courseid = required_param('courseid', PARAM_INT);
$slotid = required_param('slotid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

require_sesskey();

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);

// Load slot by ID only; course-visibility is re-checked by the helper below.
$slot = $DB->get_record('block_googlemeet_tut_slot', ['id' => $slotid], '*', MUST_EXIST);
$return = new moodle_url('/blocks/googlemeet_tutorials/schedule.php', ['courseid' => $courseid]);

// Confirm the slot is actually visible in the viewed course before acting.
if (!block_googlemeet_tutorials_slot_visible_to_user($slot, $courseid, (int) $USER->id, $context)) {
    throw new moodle_exception('nopermission', 'block_googlemeet_tutorials');
}

if ($action === 'register') {
    require_capability('block/googlemeet_tutorials:register', $context);
    try {
        block_googlemeet_tutorials_register_user($slot, $USER, $context, $courseid);
    } catch (moodle_exception $e) {
        redirect($return, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($return, get_string('successregistered', 'block_googlemeet_tutorials'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'unregister') {
    require_capability('block/googlemeet_tutorials:register', $context);
    try {
        block_googlemeet_tutorials_unregister_user($slot, $USER);
    } catch (moodle_exception $e) {
        redirect($return, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($return, get_string('successunregistered', 'block_googlemeet_tutorials'), null, \core\output\notification::NOTIFY_SUCCESS);
}

throw new moodle_exception('invaliddata', 'error');
