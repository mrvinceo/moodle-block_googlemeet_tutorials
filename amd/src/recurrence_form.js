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
 * AMD module to refresh recurrence preset labels on the slot form.
 *
 * @module     block_googlemeet_tutorials/recurrence_form
 * @copyright  2026 Paul Vincent
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {
    'use strict';

    /**
     * @param {string} labelsUrl AJAX endpoint URL (with courseid and sesskey)
     * @param {number} courseid Course id
     */
    var init = function(labelsUrl, courseid) {
        var $form = $('#id_repeat_preset').closest('form');
        if (!$form.length) {
            return;
        }

        var updateLabels = function() {
            var year = $form.find('[name="timestart[year]"]').val();
            var month = $form.find('[name="timestart[month]"]').val();
            var day = $form.find('[name="timestart[day]"]').val();
            var hour = $form.find('[name="timestart[hour]"]').val();
            var minute = $form.find('[name="timestart[minute]"]').val();
            if (!year || !month || !day) {
                return;
            }

            var $preset = $('#id_repeat_preset');
            var current = $preset.val();

            $.getJSON(labelsUrl, {
                sesskey: M.cfg.sesskey,
                courseid: courseid,
                year: year,
                month: month,
                day: day,
                hour: hour,
                minute: minute
            }).done(function(options) {
                $preset.empty();
                options.forEach(function(opt) {
                    $preset.append($('<option>', {value: opt.key, text: opt.label}));
                });
                if (current && $preset.find('option[value="' + current + '"]').length) {
                    $preset.val(current);
                }
            });
        };

        $form.on('change', '[name^="timestart"]', updateLabels);
    };

    return {init: init};
});
