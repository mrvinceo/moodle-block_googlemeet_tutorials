<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/locallib.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/slot_form.php');

use block_googlemeet_tutorials\local\recurrence;

$courseid = required_param('courseid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('block/googlemeet_tutorials:manageslots', $context);

$PAGE->set_url(new moodle_url('/blocks/googlemeet_tutorials/editslot.php', ['courseid' => $courseid, 'id' => $id]));
$PAGE->set_context($context);
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string($id ? 'editslot' : 'addslot', 'block_googlemeet_tutorials'));
$PAGE->navbar->add(get_string('managemyslots', 'block_googlemeet_tutorials'),
    new moodle_url('/blocks/googlemeet_tutorials/manage.php', ['courseid' => $courseid]));

$custom = [
    'courseid' => $courseid,
    'editing' => (bool) $id,
    'hasseries' => false,
    'savedscope' => 0,
    'lockcapacity' => false,
    'locktimes' => false,
    'lockseriesfields' => false,
];

$series = null;
if ($id) {
    // Load site-wide slots by id only (not restricted to origin courseid).
    $slot = $DB->get_record('block_googlemeet_tut_slot', ['id' => $id], '*', MUST_EXIST);
    // Authorization: must be in management course or origin course, and be owner or have viewallslots.
    if ((int) $slot->userid !== (int) $USER->id && !has_capability('block/googlemeet_tutorials:viewallslots', $context)) {
        throw new moodle_exception('nopermission', 'block_googlemeet_tutorials');
    }
    $custom['savedscope'] = (int) ($slot->scope ?? 0);
    $regcount = block_googlemeet_tutorials_count_registrations($id);
    if ($regcount > 0) {
        $custom['lockcapacity'] = true;
        $custom['locktimes'] = true;
    }
    if (!empty($slot->seriesid)) {
        $custom['hasseries'] = true;
        $series = $DB->get_record('block_googlemeet_tut_series', ['id' => $slot->seriesid], '*', MUST_EXIST);
        if (block_googlemeet_tutorials_series_has_registrations((int) $series->id)) {
            $custom['lockseriesfields'] = true;
        }
    }
} else {
    $slot = null;
    $labelsurl = (new moodle_url('/blocks/googlemeet_tutorials/recurrence_labels.php', [
        'courseid' => $courseid,
    ]))->out(false);
    $PAGE->requires->js_call_amd('block_googlemeet_tutorials/recurrence_form', 'init', [$labelsurl, $courseid]);
}

$form = new block_googlemeet_tutorials_slot_form(
    $PAGE->url,
    $custom
);

if ($slot) {
    $formdata = [
        'id' => $slot->id,
        'courseid' => $courseid,
        'title' => $slot->title,
        'intro' => $slot->intro,
        'introformat' => $slot->introformat,
        'timestart' => $slot->timestart,
        'timeend' => $slot->timeend,
        'maxstudents' => $slot->maxstudents,
        'editscope' => 'slot',
    ];
    if ($series) {
        $formdata['title'] = $series->title;
        $formdata['intro'] = $series->intro;
        $formdata['introformat'] = $series->introformat;
        $formdata['timestart'] = $series->timestart;
        $formdata['timeend'] = $series->timeend;
        $formdata['maxstudents'] = $series->maxstudents;
    }
    $form->set_data((object) $formdata);
} else {
    $timezone = block_googlemeet_tutorials_user_timezone($USER);
    $defaultstart = time() + DAYSECS;
    $form->set_data((object) [
        'courseid' => $courseid,
        'recuruntil' => recurrence::default_recuruntil($defaultstart, $timezone),
    ]);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/blocks/googlemeet_tutorials/manage.php', ['courseid' => $courseid]));
}

if ($data = $form->get_data()) {
    try {
        if (!empty($data->id)) {
            $old = $DB->get_record('block_googlemeet_tut_slot', ['id' => (int) $data->id], '*', MUST_EXIST);
            if ((int) $old->userid !== (int) $USER->id && !has_capability('block/googlemeet_tutorials:viewallslots', $context)) {
                throw new moodle_exception('nopermission', 'block_googlemeet_tutorials');
            }
            $editscope = $data->editscope ?? 'slot';
            if ($editscope === 'series' && !empty($old->seriesid)) {
                $ser = $DB->get_record('block_googlemeet_tut_series', ['id' => $old->seriesid], '*', MUST_EXIST);
                block_googlemeet_tutorials_update_series(
                    $ser,
                    $data->title,
                    $data->intro ?? '',
                    (int) $data->introformat,
                    (int) $data->timestart,
                    (int) $data->timeend,
                    (int) $data->maxstudents
                );
                $message = get_string('successseriesupdated', 'block_googlemeet_tutorials');
            } else {
                block_googlemeet_tutorials_update_slot(
                    $old,
                    $data->title,
                    $data->intro ?? '',
                    (int) $data->introformat,
                    (int) $data->timestart,
                    (int) $data->timeend,
                    (int) $data->maxstudents
                );
                $message = get_string('successcreated', 'block_googlemeet_tutorials');
            }
        } else if (!empty($data->repeat_enabled)) {
            $timezone = block_googlemeet_tutorials_user_timezone($USER);
            $config = block_googlemeet_tutorials_recurrence_config_from_form($data, $timezone);
            $duration = (int) $data->timeend - (int) $data->timestart;
            $count = count(recurrence::expand_occurrences((int) $data->timestart, $duration, $config, $timezone));
            block_googlemeet_tutorials_insert_series(
                $courseid,
                (int) $USER->id,
                $data->title,
                $data->intro ?? '',
                (int) $data->introformat,
                (int) $data->timestart,
                (int) $data->timeend,
                (int) $data->maxstudents,
                $config,
                (int) ($data->scope ?? 0)
            );
            $message = get_string('successseriescreated', 'block_googlemeet_tutorials', $count);
        } else {
            block_googlemeet_tutorials_insert_slot(
                $courseid,
                (int) $USER->id,
                $data->title,
                $data->intro ?? '',
                (int) $data->introformat,
                (int) $data->timestart,
                (int) $data->timeend,
                (int) $data->maxstudents,
                (int) ($data->scope ?? 0)
            );
            $message = get_string('successcreated', 'block_googlemeet_tutorials');
        }
    } catch (moodle_exception $e) {
        \core\notification::error($e->getMessage());
        redirect($PAGE->url);
    }
    redirect(
        new moodle_url('/blocks/googlemeet_tutorials/manage.php', ['courseid' => $courseid]),
        $message,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();

if (!block_googlemeet_tutorials_get_calendar_client((int) $USER->id)) {
    $connect = new moodle_url('/blocks/googlemeet_tutorials/connect_google.php', [
        'returnurl' => $PAGE->url->out_as_local_url(false),
    ]);
    echo $OUTPUT->notification(get_string('googlenotconnected', 'block_googlemeet_tutorials'), 'warning');
    echo html_writer::div(html_writer::link($connect, get_string('connectgoogle', 'block_googlemeet_tutorials')), 'mb-3');
}

$form->display();
echo $OUTPUT->footer();
