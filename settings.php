<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $callback = (new moodle_url('/blocks/googlemeet_tutorials/auth.php'))->out(false);

    $settings->add(new admin_setting_heading(
        'block_googlemeet_tutorials/heading',
        get_string('settingsheader', 'block_googlemeet_tutorials'),
        get_string('settingsconfig', 'block_googlemeet_tutorials', $callback)
    ));

    $settings->add(new admin_setting_configtext(
        'block_googlemeet_tutorials/apikey',
        get_string('apikey', 'block_googlemeet_tutorials'),
        get_string('apikey_desc', 'block_googlemeet_tutorials'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'block_googlemeet_tutorials/clientid',
        get_string('clientid', 'block_googlemeet_tutorials'),
        get_string('clientid_desc', 'block_googlemeet_tutorials'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'block_googlemeet_tutorials/clientsecret',
        get_string('clientsecret', 'block_googlemeet_tutorials'),
        get_string('clientsecret_desc', 'block_googlemeet_tutorials'),
        ''
    ));
}
