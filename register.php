<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Student register / unregister actions for a tutorial slot.
 *
 * @package    block_googlemeet_tutorials
 * @copyright  2026 Paul Vincent
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
$slot = $DB->get_record('block_googlemeet_tutorials_slot', ['id' => $slotid], '*', MUST_EXIST);
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
