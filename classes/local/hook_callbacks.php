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

namespace block_googlemeet_tutorials\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for Google Meet tutorials.
 *
 * @package    block_googlemeet_tutorials
 * @copyright  2026 Paul Vincent
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class hook_callbacks {

    /**
     * Remove tutorial slots, series and registrations before a course is deleted.
     *
     * Linked Google Calendar events are cancelled when possible.
     *
     * @param \core_course\hook\before_course_deleted $hook
     */
    public static function before_course_deleted(\core_course\hook\before_course_deleted $hook): void {
        if (during_initial_install()) {
            return;
        }
        if (!get_config('block_googlemeet_tutorials', 'version')) {
            return;
        }

        require_once(__DIR__ . '/../../locallib.php');
        block_googlemeet_tutorials_delete_for_course((int) $hook->course->id);
    }
}
