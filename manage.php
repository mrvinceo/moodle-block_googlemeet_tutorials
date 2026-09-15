<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/locallib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('block/googlemeet_tutorials:manageslots', $context);

$PAGE->set_url(new moodle_url('/blocks/googlemeet_tutorials/manage.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('managemyslots', 'block_googlemeet_tutorials'));

if (optional_param('syncgoogle', 0, PARAM_BOOL) && confirm_sesskey()) {
    try {
        $updated = block_googlemeet_tutorials_sync_host_slots_from_google($courseid, (int) $USER->id);
        redirect(
            $PAGE->url,
            get_string('syncfromgoogle_success', 'block_googlemeet_tutorials', $updated),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($PAGE->url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();

if (!block_googlemeet_tutorials_get_calendar_client((int) $USER->id)) {
    $connect = new moodle_url('/blocks/googlemeet_tutorials/connect_google.php', [
        'returnurl' => $PAGE->url->out_as_local_url(false),
    ]);
    echo $OUTPUT->notification(get_string('googlenotconnected', 'block_googlemeet_tutorials'), 'warning');
    echo html_writer::div(html_writer::link($connect, get_string('connectgoogle', 'block_googlemeet_tutorials')), 'mb-3');
}

$add = new moodle_url('/blocks/googlemeet_tutorials/editslot.php', ['courseid' => $courseid]);
$sync = new moodle_url('/blocks/googlemeet_tutorials/manage.php', [
    'courseid' => $courseid,
    'syncgoogle' => 1,
    'sesskey' => sesskey(),
]);
echo html_writer::div(
    html_writer::link($add, get_string('addslot', 'block_googlemeet_tutorials'), ['class' => 'btn btn-primary mb-3']) .
    ' ' .
    html_writer::link($sync, get_string('syncfromgoogle', 'block_googlemeet_tutorials'), [
        'class' => 'btn btn-secondary mb-3',
        'title' => get_string('syncfromgoogle_help', 'block_googlemeet_tutorials'),
    ]),
    'mb-2'
);
echo html_writer::div(get_string('syncfromgoogle_help', 'block_googlemeet_tutorials'), 'text-muted small mb-3');

$ismanager = has_capability('block/googlemeet_tutorials:viewallslots', $context);

// Fetch course-scoped slots for this course.
$params = ['courseid' => $courseid];
$sql = 'courseid = :courseid AND scope = 0 AND status = 1';
if (!$ismanager) {
    $sql .= ' AND userid = :userid';
    $params['userid'] = $USER->id;
}
$slots = $DB->get_records_select('block_googlemeet_tut_slot', $sql, $params, 'timestart ASC');

// Additionally fetch site-wide slots: owner sees their own; manager sees all where host manages this course.
$swsql = 'scope = 1 AND status = 1';
$swparams = [];
if (!$ismanager) {
    $swsql .= ' AND userid = :userid';
    $swparams['userid'] = $USER->id;
}
foreach ($DB->get_records_select('block_googlemeet_tut_slot', $swsql, $swparams, 'timestart ASC') as $sw) {
    if (isset($slots[$sw->id])) {
        continue;
    }
    if ($ismanager) {
        // Manager sees site-wide slots only when host has manageslots in this course.
        if (!block_googlemeet_tutorials_host_manages_in_course((int) $sw->userid, $courseid)) {
            continue;
        }
    }
    $slots[$sw->id] = $sw;
}
uasort($slots, fn($a, $b) => $a->timestart <=> $b->timestart);

$seriescounts = [];
foreach ($slots as $s) {
    if (!empty($s->seriesid)) {
        if (!isset($seriescounts[$s->seriesid])) {
            $seriescounts[$s->seriesid] = $DB->count_records('block_googlemeet_tut_slot', [
                'seriesid' => $s->seriesid,
                'status' => 1,
            ]);
        }
    }
}

$table = new html_table();
$table->head = [
    get_string('slottitle', 'block_googlemeet_tutorials'),
    get_string('timestart', 'block_googlemeet_tutorials'),
    get_string('timeend', 'block_googlemeet_tutorials'),
    get_string('maxstudents', 'block_googlemeet_tutorials'),
    get_string('registered', 'block_googlemeet_tutorials'),
    get_string('recurrence', 'block_googlemeet_tutorials'),
    get_string('scope', 'block_googlemeet_tutorials'),
    get_string('usegroups', 'block_googlemeet_tutorials'),
    '',
];
foreach ($slots as $s) {
    $regs = block_googlemeet_tutorials_count_registrations((int) $s->id);
    $edit = new moodle_url('/blocks/googlemeet_tutorials/editslot.php', ['courseid' => $courseid, 'id' => $s->id]);
    $del = new moodle_url('/blocks/googlemeet_tutorials/deleteslot.php', ['courseid' => $courseid, 'slotid' => $s->id, 'sesskey' => sesskey()]);
    $actions = html_writer::link($edit, get_string('edit')) . ' ' .
        html_writer::link($del, get_string('delete'));

    if (!empty($s->seriesid) && !empty($seriescounts[$s->seriesid])) {
        $recurrencecell = get_string('recurrence_series', 'block_googlemeet_tutorials', $seriescounts[$s->seriesid]);
    } else {
        $recurrencecell = get_string('recurrence_none', 'block_googlemeet_tutorials');
    }

    $scopecell = ((int) ($s->scope ?? 0) === 1)
        ? get_string('scopelabel_sitewide', 'block_googlemeet_tutorials')
        : get_string('scopelabel_course', 'block_googlemeet_tutorials');

    $groupscell = (!isset($s->usegroups) || (int) $s->usegroups === 1)
        ? get_string('yes')
        : get_string('no');

    $table->data[] = [
        format_string($s->title),
        userdate($s->timestart),
        userdate($s->timeend),
        (int) $s->maxstudents,
        $regs,
        $recurrencecell,
        $scopecell,
        $groupscell,
        $actions,
    ];
}
if ($slots) {
    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('noslots', 'block_googlemeet_tutorials'), 'alert alert-info');
}

echo $OUTPUT->footer();
