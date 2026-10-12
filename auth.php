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
 * OAuth callback that stores the Google Calendar token for the current user.
 *
 * @package    block_googlemeet_tutorials
 * @copyright  2026 Paul Vincent
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/blocks/googlemeet_tutorials/locallib.php');

require_login();

global $SESSION, $USER;

$code = optional_param('code', '', PARAM_RAW);
$state = required_param('state', PARAM_ALPHANUM);

$returnurl = !empty($SESSION->googlemeet_tutorials_return_url)
    ? $SESSION->googlemeet_tutorials_return_url
    : (new moodle_url('/'))->out(false);
unset($SESSION->googlemeet_tutorials_return_url);

$expectedstate = $SESSION->googlemeet_tutorials_oauth_state ?? '';
unset($SESSION->googlemeet_tutorials_oauth_state);

if ($expectedstate === '' || !hash_equals($expectedstate, $state)) {
    redirect(new moodle_url($returnurl), get_string('oauthstateinvalid', 'block_googlemeet_tutorials'),
        null, \core\output\notification::NOTIFY_ERROR);
}

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
