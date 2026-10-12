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
 * Admin settings for Google API credentials.
 *
 * @package    block_googlemeet_tutorials
 * @copyright  2026 Paul Vincent
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
