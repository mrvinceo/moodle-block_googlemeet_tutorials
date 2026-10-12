<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Starts the Google OAuth consent flow for Calendar access.
 *
 * @package    block_googlemeet_tutorials
 * @copyright  2026 Paul Vincent
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
