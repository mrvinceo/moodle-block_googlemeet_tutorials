<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/locallib.php');

require_login();

$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
if (empty($returnurl)) {
    $returnurl = (new moodle_url('/'))->out(false);
}

global $SESSION;
$SESSION->googlemeet_tutorials_return_url = $returnurl;

// CSRF / login-CSRF protection for the OAuth redirect (RFC 6749 §10.12).
$state = random_string(32);
$SESSION->googlemeet_tutorials_oauth_state = $state;

$client = block_googlemeet_tutorials_new_oauth_client();
$client->setState($state);
redirect($client->createAuthUrl());
