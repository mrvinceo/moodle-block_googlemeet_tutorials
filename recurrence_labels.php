<?php
// This file is part of Moodle - http://moodle.org/

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
