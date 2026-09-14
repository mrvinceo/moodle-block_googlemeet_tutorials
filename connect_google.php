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

$client = block_googlemeet_tutorials_new_oauth_client();
redirect($client->createAuthUrl());
