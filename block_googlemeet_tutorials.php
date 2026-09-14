<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/locallib.php');

/**
 * Block instance.
 */
class block_googlemeet_tutorials extends block_base {

    /**
     * Initialise.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_googlemeet_tutorials');
    }

    /**
     * @return bool
     */
    public function has_config() {
        return true;
    }

    /**
     * @return array
     */
    public function applicable_formats() {
        return [
            'course' => true,
            'my' => false,
        ];
    }

    /**
     * @return stdClass
     */
    public function get_content() {
        global $COURSE, $USER;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';
        $this->content->text = '';

        if (empty($COURSE->id) || $COURSE->id == SITEID) {
            $this->content->text = html_writer::div(get_string('onlycourse', 'block_googlemeet_tutorials'), 'alert alert-info');
            return $this->content;
        }

        $context = context_course::instance($COURSE->id);
        if (!has_capability('block/googlemeet_tutorials:viewschedule', $context)) {
            return $this->content;
        }

        $courseid = (int) $COURSE->id;
        $scheduleurl = new moodle_url('/blocks/googlemeet_tutorials/schedule.php', ['courseid' => $courseid]);
        $this->content->text .= html_writer::link($scheduleurl, get_string('viewschedule', 'block_googlemeet_tutorials'),
            ['class' => 'btn btn-primary mb-2']);

        if (has_capability('block/googlemeet_tutorials:manageslots', $context)) {
            $manageurl = new moodle_url('/blocks/googlemeet_tutorials/manage.php', ['courseid' => $courseid]);
            $this->content->text .= ' ' . html_writer::link($manageurl, get_string('managemyslots', 'block_googlemeet_tutorials'),
                ['class' => 'btn btn-secondary mb-2']);
        }

        $slots = block_googlemeet_tutorials_visible_slots($courseid, (int) $USER->id, $context);
        $upcoming = array_filter($slots, function($s) {
            return (int) $s->timestart >= time() - 300;
        });
        $upcoming = array_slice($upcoming, 0, 5);

        if ($upcoming) {
            $this->content->text .= html_writer::tag('h6', get_string('pluginname', 'block_googlemeet_tutorials'), ['class' => 'mt-2']);
            $list = html_writer::start_tag('ul', ['class' => 'list-unstyled small']);
            foreach ($upcoming as $s) {
                $namefields = 'id,' . implode(',', \core_user\fields::get_name_fields());
                $host = fullname(core_user::get_user($s->userid, $namefields));
                $when = userdate($s->timestart, '', false, true);
                $list .= html_writer::tag('li', s($s->title) . ' — ' . $when . ' (' . $host . ')');
            }
            $list .= html_writer::end_tag('ul');
            $this->content->text .= $list;
        }

        return $this->content;
    }
}
