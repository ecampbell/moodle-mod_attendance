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
 * Form for uploading scanned documents in the rimport report
 *
 * @package       mod
 * @subpackage    attendance
 * @author        Juergen Zimmer <zimmerj7@univie.ac.at>
 * @copyright     2015 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @since         Moodle 2.1+
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 **/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/formslib.php');

class signinsheets_upload_form extends moodleform {

    public function definition() {
        $mform = $this->_form;
        // -------------------------------------------------------------------------------

        // The file to import.
        $mform->addElement('header', 'importfileupload', get_string('importforms', 'signinsheets_rimport'));

        $mform->addElement('filepicker', 'newfile', get_string('ziporimagefile', 'signinsheets_rimport'), null,
                array('subdirs' => 0, 'accepted_types' =>
                        array('.jpeg', 'JPEG', 'JPG', 'jpg', '.png', '.zip',
                              '.ZIP', '.tif', '.TIF', '.tiff', '.TIFF' , ".pdf", ".PDF")));

        $mform->addRule('newfile', null, 'required', null, 'client');

        // Submit button.
        $mform->addElement('submit', 'submitbutton', get_string('import', 'signinsheets_rimport'));
    }
}