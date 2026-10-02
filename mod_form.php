<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Add and edit form for a Web Conference activity.
 *
 * Why: Moodle opens this form when a teacher adds or edits the activity.
 * What: name, intro, and standard course-module fields.
 * How: extends moodleform_mod. FastPubSub fields come later.
 *
 * Example:
 *   // Moodle loads this class by name: mod_webconferens_mod_form.
 *
 * @package    mod_webconferens
 * @copyright  2026 FastPubSub <info@fastpubsub.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Fields for one Web Conference: name, description, standard module options.
 *
 * Why: teachers need a Moodle form, not a custom page, to create the activity.
 * What: builds the add/edit form.
 * How: name and intro now; live-session fields later.
 *
 * Example:
 *   // Core instantiates this class when opening the activity form.
 */
class mod_webconferens_mod_form extends moodleform_mod {
    /**
     * Builds the form fields.
     *
     * Why: moodleform calls this to fill the form object.
     * What: header, required name, intro, standard module bits, buttons.
     * How: $this->_form plus standard_intro_elements and standard_coursemodule_elements.
     * When: every time the add or edit activity page opens.
     *
     * Example:
     *   $mform = $this->_form;
     *   $mform->addElement('text', 'name', get_string('name'));
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        // This release only ships the meeting. Lesson and conference stay out of the form.
        $types = [
            'all_to_all' => get_string('roomtype_all_to_all', 'mod_webconferens'),
        ];
        $mform->addElement('select', 'roomtype', get_string('roomtype', 'mod_webconferens'), $types);
        $mform->setType('roomtype', PARAM_ALPHANUMEXT);
        $mform->setDefault('roomtype', 'all_to_all');
        $mform->addHelpButton('roomtype', 'roomtype', 'mod_webconferens');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Rejects any live format other than the meeting.
     *
     * Why: the select lists only the meeting, and a posted value must match it.
     * What: returns a form error when roomtype is not all_to_all.
     * How: compares the submitted roomtype after the parent checks.
     * When: Moodle validates the add or edit activity form.
     *
     * Example:
     *   $errors = $form->validation($data, $files);
     *
     * @param array $data submitted form values
     * @param array $files uploaded files
     * @return array field name to error string
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Only the meeting is offered in this release.
        if (($data['roomtype'] ?? '') !== 'all_to_all') {
            $errors['roomtype'] = get_string('roomtype_onlymeeting', 'mod_webconferens');
        }

        return $errors;
    }
}
