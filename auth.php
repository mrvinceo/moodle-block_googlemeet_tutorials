<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/locallib.php');

require_login();

global $SESSION, $DB, $USER;

$code = optional_param('code', '', PARAM_RAW);
$returnurl = !empty($SESSION->googlemeet_tutorials_return_url)
    ? $SESSION->googlemeet_tutorials_return_url
    : (new moodle_url('/'))->out(false);
unset($SESSION->googlemeet_tutorials_return_url);

if (!$code) {
    redirect(new moodle_url($returnurl), get_string('googlenotconnected', 'block_googlemeet_tutorials'),
        null, \core\output\notification::NOTIFY_ERROR);
}

$client = block_googlemeet_tutorials_new_oauth_client();
$token = $client->fetchAccessTokenWithAuthCode($code);
if (empty($token['access_token'])) {
    redirect(new moodle_url($returnurl), get_string('googlenotconnected', 'block_googlemeet_tutorials'),
        null, \core\output\notification::NOTIFY_ERROR);
}
$client->setAccessToken($token);
block_googlemeet_tutorials_persist_google_token((int) $USER->id, $client);

redirect(new moodle_url($returnurl), get_string('successgoogleauth', 'block_googlemeet_tutorials'),
    null, \core\output\notification::NOTIFY_SUCCESS);
