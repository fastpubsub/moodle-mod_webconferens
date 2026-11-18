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

namespace mod_webconferens\form;

use mod_webconferens\guest_entry;

/**
 * Asks a guest what name the room should show.
 *
 * Why: the Moodle guest account has one shared name, which is useless in a room.
 * What: one text field and a submit button.
 * How: moodleform on view.php. The last session name is filled in by the page.
 *
 * Example:
 *   $mform = new \mod_webconferens\form\guest_name();
 *   if ($data = $mform->get_data()) {
 *       guest_entry::save_name($data->displayname);
 *   }
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guest_name extends \moodleform {
    /**
     * Adds the name field.
     *
     * Why: the guest must type a name before the room starts.
     * What: one required text input and the enter button.
     * How: PARAM_TEXT, with a length cap of guest_entry::NAME_MAX.
     * When: Moodle calls this while building the form.
     *
     * Example:
     *   $mform = new \mod_webconferens\form\guest_name();
     *   $mform->display();
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement(
            'text',
            'displayname',
            get_string('guestname', 'mod_webconferens'),
            ['maxlength' => guest_entry::NAME_MAX, 'size' => 40]
        );
        $mform->setType('displayname', PARAM_TEXT);
        $mform->addRule('displayname', get_string('guestnamerequired', 'mod_webconferens'), 'required', null, 'client');
        $mform->addRule(
            'displayname',
            get_string('guestnametoolong', 'mod_webconferens', guest_entry::NAME_MAX),
            'maxlength',
            guest_entry::NAME_MAX,
            'client'
        );

        $this->add_action_buttons(false, get_string('guestjoin', 'mod_webconferens'));
    }

    /**
     * Rejects an empty name and a name that is past the cap.
     *
     * Why: spaces alone are not a name, and the client check can be skipped.
     * What: returns field errors.
     * How: cleans the text, then checks it is present and short enough.
     * When: Moodle calls this on submit.
     *
     * Example:
     *   $errors = $mform->validation(['displayname' => '  '], []);
     *
     * @param array $data submitted values
     * @param array $files uploaded files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $name = guest_entry::clean_name($data['displayname'] ?? '');

        // A blank line must not open the room.
        if ($name === '') {
            $errors['displayname'] = get_string('guestnamerequired', 'mod_webconferens');
        } else if (\core_text::strlen($name) > guest_entry::NAME_MAX) {
            // The field cap can be skipped by a crafted submit.
            $errors['displayname'] = get_string('guestnametoolong', 'mod_webconferens', guest_entry::NAME_MAX);
        }

        return $errors;
    }
}
