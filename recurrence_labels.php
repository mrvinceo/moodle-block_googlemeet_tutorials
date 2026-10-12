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
 * AJAX endpoint returning recurrence preset option labels.
 *
 * @package    block_googlemeet_tutorials
 * @copyright  2026 Paul Vincent
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/locallib.php');

use block_googlemeet_tutorials\local\recurrence;

$courseid = required_param('courseid', PARAM_INT);
$year = required_param('year', PARAM_INT);
$month = required_param('month', PARAM_INT);
$day = required_param('day', PARAM_INT);
$hour = required_param('hour', PARAM_INT);
$minute = required_param('minute', PARAM_INT);

require_sesskey();
require_login();
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('block/googlemeet_tutorials:manageslots', $context);

$timestart = make_timestamp($year, $month, $day, $hour, $minute);
$timezone = block_googlemeet_tutorials_user_timezone($USER);

$options = recurrence::build_preset_options($timestart, $timezone);
$out = [];
foreach ($options as $key => $label) {
    $out[] = ['key' => $key, 'label' => $label];
}

header('Content-Type: application/json');
echo json_encode($out);
die;
