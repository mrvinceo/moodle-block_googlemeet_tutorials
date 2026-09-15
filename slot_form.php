<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use block_googlemeet_tutorials\local\recurrence;

/**
 * Add / edit tutorial slot.
 */
class block_googlemeet_tutorials_slot_form extends moodleform {

    /**
     * Form definition.
     */
    public function definition() {
        global $USER;

        $mform = $this->_form;
        $courseid = $this->_customdata['courseid'];
        $editing = !empty($this->_customdata['editing']);
        $hasseries = !empty($this->_customdata['hasseries']);

        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        if ($hasseries) {
            $scopeoptions = [
                'slot' => get_string('editscope_slot', 'block_googlemeet_tutorials'),
                'series' => get_string('editscope_series', 'block_googlemeet_tutorials'),
            ];
            $mform->addElement('select', 'editscope', get_string('editscope', 'block_googlemeet_tutorials'), $scopeoptions);
            $mform->setDefault('editscope', 'slot');
            $mform->setType('editscope', PARAM_ALPHA);
        }

        $mform->addElement('text', 'title', get_string('slottitle', 'block_googlemeet_tutorials'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');

        $mform->addElement('textarea', 'intro', get_string('intro', 'block_googlemeet_tutorials'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('intro', PARAM_TEXT);
        $mform->addElement('hidden', 'introformat', FORMAT_PLAIN);
        $mform->setType('introformat', PARAM_INT);

        $mform->addElement('date_time_selector', 'timestart', get_string('timestart', 'block_googlemeet_tutorials'));
        $mform->addElement('date_time_selector', 'timeend', get_string('timeend', 'block_googlemeet_tutorials'));

        if (!$editing) {
            $scopeopts = [
                0 => get_string('scope_course', 'block_googlemeet_tutorials'),
                1 => get_string('scope_sitewide', 'block_googlemeet_tutorials'),
            ];
            $mform->addElement('select', 'scope', get_string('scope', 'block_googlemeet_tutorials'), $scopeopts);
            $mform->setType('scope', PARAM_INT);
            $mform->setDefault('scope', 0);
            $mform->addElement('static', 'scope_sitewide_note', '',
                html_writer::tag('small', get_string('scope_sitewide_help', 'block_googlemeet_tutorials'),
                    ['class' => 'form-text text-muted']));
        } else {
            $savedscope = (int) ($this->_customdata['savedscope'] ?? 0);
            $scopelabel = $savedscope
                ? get_string('scope_sitewide', 'block_googlemeet_tutorials')
                : get_string('scope_course', 'block_googlemeet_tutorials');
            $mform->addElement('static', 'scope_display',
                get_string('scope', 'block_googlemeet_tutorials'), $scopelabel);
            $mform->addElement('hidden', 'scope', $savedscope);
            $mform->setType('scope', PARAM_INT);
        }

        if (!$editing) {
            $mform->addElement('advcheckbox', 'repeat_enabled', get_string('repeatenabled', 'block_googlemeet_tutorials'));
            $mform->setType('repeat_enabled', PARAM_INT);

            $timezone = block_googlemeet_tutorials_user_timezone($USER);
            $defaultstart = time() + DAYSECS;
            $presets = recurrence::build_preset_options($defaultstart, $timezone);
            $mform->addElement('select', 'repeat_preset', get_string('repeatpreset', 'block_googlemeet_tutorials'), $presets);
            $mform->setType('repeat_preset', PARAM_ALPHANUMEXT);
            $mform->setDefault('repeat_preset', recurrence::PRESET_WEEKLY);
            $mform->hideIf('repeat_preset', 'repeat_enabled', 'notchecked');

            $mform->addElement('text', 'recurrence_interval', get_string('recurrence_interval', 'block_googlemeet_tutorials'), ['size' => 3]);
            $mform->setType('recurrence_interval', PARAM_INT);
            $mform->setDefault('recurrence_interval', 1);
            $mform->hideIf('recurrence_interval', 'repeat_enabled', 'notchecked');
            $mform->hideIf('recurrence_interval', 'repeat_preset', 'neq', recurrence::PRESET_CUSTOM);

            $freqoptions = [
                'DAILY' => get_string('frequency_daily', 'block_googlemeet_tutorials'),
                'WEEKLY' => get_string('frequency_weekly', 'block_googlemeet_tutorials'),
                'MONTHLY' => get_string('frequency_monthly', 'block_googlemeet_tutorials'),
                'YEARLY' => get_string('frequency_yearly', 'block_googlemeet_tutorials'),
            ];
            $mform->addElement('select', 'recurrence_frequency', get_string('recurrence_frequency', 'block_googlemeet_tutorials'), $freqoptions);
            $mform->setType('recurrence_frequency', PARAM_ALPHA);
            $mform->setDefault('recurrence_frequency', 'WEEKLY');
            $mform->hideIf('recurrence_frequency', 'repeat_enabled', 'notchecked');
            $mform->hideIf('recurrence_frequency', 'repeat_preset', 'neq', recurrence::PRESET_CUSTOM);

            $dayoptions = [
                'MO' => get_string('monday', 'calendar'),
                'TU' => get_string('tuesday', 'calendar'),
                'WE' => get_string('wednesday', 'calendar'),
                'TH' => get_string('thursday', 'calendar'),
                'FR' => get_string('friday', 'calendar'),
                'SA' => get_string('saturday', 'calendar'),
                'SU' => get_string('sunday', 'calendar'),
            ];
            $mform->addElement('select', 'recurrence_byday', get_string('recurrence_byday', 'block_googlemeet_tutorials'), $dayoptions, ['multiple' => true]);
            $mform->setType('recurrence_byday', PARAM_TEXT);
            $mform->hideIf('recurrence_byday', 'repeat_enabled', 'notchecked');
            $mform->hideIf('recurrence_byday', 'repeat_preset', 'neq', recurrence::PRESET_CUSTOM);
            $mform->hideIf('recurrence_byday', 'recurrence_frequency', 'neq', 'WEEKLY');

            $defaultuntil = recurrence::default_recuruntil($defaultstart, $timezone);
            $mform->addElement('date_selector', 'recuruntil', get_string('recuruntil', 'block_googlemeet_tutorials'));
            $mform->hideIf('recuruntil', 'repeat_enabled', 'notchecked');
        }

        $mform->addElement('text', 'maxstudents', get_string('maxstudents', 'block_googlemeet_tutorials'), ['size' => 4]);
        $mform->setType('maxstudents', PARAM_INT);
        $mform->setDefault('maxstudents', 1);
        $mform->addRule('maxstudents', null, 'required', null, 'client');
        $mform->addRule('maxstudents', null, 'numeric', null, 'client');

        $mform->addElement('advcheckbox', 'usegroups', get_string('usegroups', 'block_googlemeet_tutorials'),
            get_string('usegroups_desc', 'block_googlemeet_tutorials'));
        $mform->setType('usegroups', PARAM_INT);
        $mform->setDefault('usegroups', 1);

        if (!empty($this->_customdata['lockcapacity'])) {
            $mform->freeze('maxstudents');
        }
        if (!empty($this->_customdata['locktimes'])) {
            $mform->freeze('timestart');
            $mform->freeze('timeend');
        }
        if (!empty($this->_customdata['lockseriesfields'])) {
            $mform->freeze('timestart');
            $mform->freeze('timeend');
            $mform->freeze('maxstudents');
        }

        $this->add_action_buttons();
    }

    /**
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $USER;

        $errors = parent::validation($data, $files);
        if (!empty($data['timestart']) && !empty($data['timeend']) && $data['timestart'] >= $data['timeend']) {
            $errors['timeend'] = get_string('invaliddata', 'error');
        }
        if (isset($data['maxstudents']) && (int) $data['maxstudents'] < 1) {
            $errors['maxstudents'] = get_string('invaliddata', 'error');
        }

        if (empty($data['id']) && !empty($data['repeat_enabled'])) {
            $timezone = block_googlemeet_tutorials_user_timezone($USER);
            $recuruntil = recurrence::normalise_recuruntil($data['recuruntil'], $timezone);
            if ($recuruntil < $data['timestart']) {
                $errors['recuruntil'] = get_string('recurrenceuntilbeforestart', 'block_googlemeet_tutorials');
            } else {
                $duration = (int) $data['timeend'] - (int) $data['timestart'];
                $byday = '';
                if (!empty($data['recurrence_byday']) && is_array($data['recurrence_byday'])) {
                    $byday = implode(',', $data['recurrence_byday']);
                } else if (!empty($data['recurrence_byday'])) {
                    $byday = $data['recurrence_byday'];
                }
                $config = [
                    'preset' => $data['repeat_preset'] ?? recurrence::PRESET_WEEKLY,
                    'timestart' => (int) $data['timestart'],
                    'recuruntil' => $recuruntil,
                    'timezone' => $timezone,
                    'recurrence_interval' => (int) ($data['recurrence_interval'] ?? 1),
                    'recurrence_frequency' => $data['recurrence_frequency'] ?? 'WEEKLY',
                    'recurrence_byday' => $byday,
                ];
                $occurrences = recurrence::expand_occurrences(
                    (int) $data['timestart'],
                    $duration,
                    $config,
                    $timezone
                );
                if (count($occurrences) < 2) {
                    $errors['recuruntil'] = get_string('recurrenceminoccurrences', 'block_googlemeet_tutorials');
                } else if (count($occurrences) >= recurrence::MAX_OCCURRENCES) {
                    $errors['recuruntil'] = get_string(
                        'recurrencemaxoccurrences',
                        'block_googlemeet_tutorials',
                        recurrence::MAX_OCCURRENCES
                    );
                }
            }
        }

        return $errors;
    }
}
