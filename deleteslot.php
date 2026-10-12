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
 * Delete a tutorial slot (and related Google Calendar event when linked).
 *
 * @package    block_googlemeet_tutorials
 * @copyright  2026 Paul Vincent
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/locallib.php');

$courseid = required_param('courseid', PARAM_INT);
$slotid = required_param('slotid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);
$scope = optional_param('scope', 'slot', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('block/googlemeet_tutorials:manageslots', $context);

// Load by id so site-wide slots (origin course != viewed course) can be deleted,
// then enforce course/scope + ownership rules.
$slot = $DB->get_record('block_googlemeet_tutorials_slot', ['id' => $slotid], '*', MUST_EXIST);
block_googlemeet_tutorials_require_can_manage_slot($slot, $courseid, $context);

$series = null;
$seriescount = 0;
if (!empty($slot->seriesid)) {
    $series = $DB->get_record('block_googlemeet_tutorials_series', ['id' => $slot->seriesid], '*', MUST_EXIST);
    $seriescount = count(block_googlemeet_tutorials_get_series_slots((int) $series->id));
}

$manage = new moodle_url('/blocks/googlemeet_tutorials/manage.php', ['courseid' => $courseid]);
$urlself = new moodle_url('/blocks/googlemeet_tutorials/deleteslot.php', [
    'courseid' => $courseid,
    'slotid' => $slotid,
    'confirm' => 1,
    'scope' => $scope,
    'sesskey' => sesskey(),
]);

$PAGE->set_url(new moodle_url('/blocks/googlemeet_tutorials/deleteslot.php', [
    'courseid' => $courseid,
    'slotid' => $slotid,
    'scope' => $scope,
]));
$PAGE->set_context($context);
$PAGE->set_heading($course->fullname);

if ($confirm && confirm_sesskey()) {
    try {
        if ($scope === 'series' && $series) {
            block_googlemeet_tutorials_delete_series($series);
            redirect($manage, get_string('successseriesdeleted', 'block_googlemeet_tutorials'),
                null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            block_googlemeet_tutorials_delete_slot($slot);
            redirect($manage, get_string('successdeleted', 'block_googlemeet_tutorials'),
                null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
        redirect($manage);
    }
}

echo $OUTPUT->header();

if ($series && $seriescount > 1) {
    echo html_writer::tag('p', get_string('deletescope', 'block_googlemeet_tutorials'));
    $sloturl = new moodle_url('/blocks/googlemeet_tutorials/deleteslot.php', [
        'courseid' => $courseid,
        'slotid' => $slotid,
        'scope' => 'slot',
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);
    $seriesurl = new moodle_url('/blocks/googlemeet_tutorials/deleteslot.php', [
        'courseid' => $courseid,
        'slotid' => $slotid,
        'scope' => 'series',
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);
    echo html_writer::start_div('mb-3');
    echo html_writer::link($sloturl, get_string('deletescope_slot', 'block_googlemeet_tutorials'),
        ['class' => 'btn btn-secondary mr-2']);
    echo ' ';
    echo html_writer::link($seriesurl, get_string('deletescope_series', 'block_googlemeet_tutorials', $seriescount),
        ['class' => 'btn btn-danger mr-2']);
    echo ' ';
    echo html_writer::link($manage, get_string('cancel', 'block_googlemeet_tutorials'), ['class' => 'btn btn-link']);
    echo html_writer::end_div();
    echo html_writer::tag('p', get_string('deleteslotconfirm', 'block_googlemeet_tutorials'), ['class' => 'text-muted small']);
} else {
    echo $OUTPUT->confirm(get_string('deleteslotconfirm', 'block_googlemeet_tutorials'), $urlself, $manage);
}

echo $OUTPUT->footer();
