<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/locallib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('block/googlemeet_tutorials:viewschedule', $context);

$PAGE->set_url(new moodle_url('/blocks/googlemeet_tutorials/schedule.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('viewschedule', 'block_googlemeet_tutorials'));

echo $OUTPUT->header();

echo html_writer::div(get_string('meetlinkvisible', 'block_googlemeet_tutorials'), 'alert alert-secondary small mb-3');

$slots = block_googlemeet_tutorials_visible_slots($courseid, (int) $USER->id, $context);

$table = new html_table();
$table->head = [
    get_string('slottitle', 'block_googlemeet_tutorials'),
    get_string('host', 'block_googlemeet_tutorials'),
    get_string('timestart', 'block_googlemeet_tutorials'),
    get_string('timeend', 'block_googlemeet_tutorials'),
    get_string('maxstudents', 'block_googlemeet_tutorials'),
    get_string('registered', 'block_googlemeet_tutorials'),
    get_string('meetlink', 'block_googlemeet_tutorials'),
    '',
];

foreach ($slots as $s) {
    $namefields = 'id,email,' . implode(',', \core_user\fields::get_name_fields());
    $hostuser = core_user::get_user($s->userid, $namefields);
    $host = $hostuser ? fullname($hostuser) : (string) $s->userid;
    $regs = block_googlemeet_tutorials_count_registrations((int) $s->id);
    $full = $regs >= (int) $s->maxstudents;
    $registered = block_googlemeet_tutorials_user_is_registered((int) $s->id, (int) $USER->id);
    $canmeet = block_googlemeet_tutorials_user_can_view_meet_link($s, $courseid, (int) $USER->id, $context);
    $meetcell = '-';
    if ($canmeet && !empty($s->meeturl)) {
        $meetcell = html_writer::link($s->meeturl, get_string('meetlink', 'block_googlemeet_tutorials'), ['target' => '_blank']);
    }

    $actions = '';
    if (has_capability('block/googlemeet_tutorials:register', $context) && $s->timestart >= time() - 60) {
        if ($registered) {
            $url = new moodle_url('/blocks/googlemeet_tutorials/register.php', [
                'courseid' => $courseid,
                'slotid' => $s->id,
                'action' => 'unregister',
                'sesskey' => sesskey(),
            ]);
            $actions = html_writer::link($url, get_string('unregister', 'block_googlemeet_tutorials'));
        } else if (!$full) {
            $url = new moodle_url('/blocks/googlemeet_tutorials/register.php', [
                'courseid' => $courseid,
                'slotid' => $s->id,
                'action' => 'register',
                'sesskey' => sesskey(),
            ]);
            $actions = html_writer::link($url, get_string('register', 'block_googlemeet_tutorials'));
        } else {
            $actions = html_writer::span(get_string('full', 'block_googlemeet_tutorials'), 'text-muted');
        }
    }

    $table->data[] = [
        format_string($s->title),
        $host,
        userdate($s->timestart),
        userdate($s->timeend),
        (int) $s->maxstudents,
        $regs,
        $meetcell,
        $actions,
    ];
}

if ($slots) {
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('noslots', 'block_googlemeet_tutorials'), 'alert alert-info');
}

echo $OUTPUT->footer();
