<?php
// This file is part of mod_attendance for Moodle - http://moodle.org/
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
 * Internal library of functions for module signinsheet
 *
 * All the signinsheet specific functions, needed to implement the module
 * logic, should go here. Never include this file from your lib.php!
 *
 * @package       mod
 * @subpackage    attendance
 * @author        Juergen Zimmer <zimmerj7@univie.ac.at>
 * @copyright     2015 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @since         Moodle 2.2+
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/engine/questionusage.php');

// These are the old error codes from the Moodle 1.9 module. We still need them for migration.
define("SIGNINSHEET_IMPORT_LMS", "1");
define("SIGNINSHEET_IMPORT_OK", "0");
define("SIGNINSHEET_IMPORT_CORRECTED", "1");
define("SIGNINSHEET_IMPORT_DOUBLE", "2");
define("SIGNINSHEET_IMPORT_ITEM_ERROR", "3");
define("SIGNINSHEET_IMPORT_DOUBLE_ERROR", "11");
define("SIGNINSHEET_IMPORT_USER_ERROR", "12");
define("SIGNINSHEET_IMPORT_GROUP_ERROR", "13");
define("SIGNINSHEET_IMPORT_FATAL_ERROR", "14");
define("SIGNINSHEET_IMPORT_INSECURE_ERROR", "15");
define("SIGNINSHEET_IMPORT_PAGE_ERROR", "16");
define("SIGNINSHEET_IMPORT_SINGLE_ERROR", "17"); // This is not really an error.
// It occures, when multipage answer sheets are scanned.
define("SIGNINSHEET_IMPORT_DOUBLE_PAGE_ERROR", "18"); // New error for double pages (e.g. page 2 occurs twice for as student).
define("SIGNINSHEET_IMPORT_DIFFERING_PAGE_ERROR", "19"); // New error for double pages that have different results (rawdata).

// Codes for lists of participants.
define("SIGNINSHEET_PART_FATAL_ERROR", "21");   // Over 20 indicates, it is a participants error.
define("SIGNINSHEET_PART_INSECURE_ERROR", "22");
define("SIGNINSHEET_PART_USER_ERROR", "23");
define("SIGNINSHEET_PART_LIST_ERROR", "24");
define("SIGNINSHEET_IMPORT_NUMUSERS", "50");

define('SIGNINSHEET_USER_FORMULA_REGEXP', "/^([^\[]*)\[([\-]?[0-9]+)\]([^\=]*)=([a-z]+)$/");

define('SIGNINSHEET_GROUP_LETTERS', "ABCDEFGHIJKL");  // Letters for naming signinsheet groups.

define('SIGNINSHEET_PDF_FORMAT', 0);   // PDF file format for question sheets.
define('SIGNINSHEET_DOCX_FORMAT', 1);  // DOCX file format for question sheets.
define('SIGNINSHEET_LATEX_FORMAT', 2);  // LATEX file format for question sheets.

define('NUMBERS_PER_PAGE', 30);        // Number of students on participants list.
define('OQ_IMAGE_WIDTH', 860);         // Width of correction form.

class signinsheets_question_usage_by_activity extends question_usage_by_activity {

    public function get_clone($qinstances) {
        // The new quba doesn't have to be cloned, so we can use the parent class.
        $newquba = question_engine::make_questions_usage_by_activity($this->owningcomponent, $this->context);
        $newquba->set_preferred_behaviour('immediatefeedback');

        foreach ($this->get_slots() as $slot) {
            $slotquestion = $this->get_question($slot);
            $attempt = $this->get_question_attempt($slot);

            // We have to check for the type because we might have old migrated templates
            // that could contain description questions.
            if ($slotquestion->get_type_name() == 'multichoice' || $slotquestion->get_type_name() == 'multichoiceset') {
                $order = $slotquestion->get_order($attempt);  // Order of the answers.
                $order = implode(',', $order);
                $newslot = $newquba->add_question($slotquestion, $qinstances[$slotquestion->id]->maxmark);
                $qa = $newquba->get_question_attempt($newslot);
                $qa->start('immediatefeedback', 1, array('_order' => $order));
            }
        }
        question_engine::save_questions_usage_by_activity($newquba);
        return $newquba;
    }

    /**
     * Create a question_usage_by_activity from records loaded from the database.
     *
     * For internal use only.
     *
     * @param Iterator $records Raw records loaded from the database.
     * @param int $questionattemptid The id of the question_attempt to extract.
     * @return question_usage_by_activity The newly constructed usage.
     */
    public static function load_from_records($records, $qubaid) {
        $record = $records->current();
        while ($record->qubaid != $qubaid) {
            $records->next();
            if (!$records->valid()) {
                throw new coding_exception("Question usage $qubaid not found in the database.");
            }
            $record = $records->current();
        }

        $quba = new signinsheets_question_usage_by_activity($record->component,
                context::instance_by_id($record->contextid));
        $quba->set_id_from_database($record->qubaid);
        $quba->set_preferred_behaviour($record->preferredbehaviour);

        $quba->observer = new question_engine_unit_of_work($quba);

        while ($record && $record->qubaid == $qubaid && !is_null($record->slot)) {
            $quba->questionattempts[$record->slot] = question_attempt::load_from_records($records,
                    $record->questionattemptid, $quba->observer,
                    $quba->get_preferred_behaviour());
            if ($records->valid()) {
                $record = $records->current();
            } else {
                $record = false;
            }
        }

        return $quba;
    }
}

/**
 *
 * @param mixed $signinsheet The signinsheet
 * @return array returns an array of offline group numbers
 */
function signinsheets_get_empty_groups($signinsheet) {
    global $DB;

    $emptygroups = array();

    if ($groups = $DB->get_records('signinsheets_groups',
                                   array('signinsheetid' => $signinsheet->id), 'number', '*', 0, $signinsheet->numgroups)) {
        foreach ($groups as $group) {
            $questions = signinsheets_get_group_question_ids($signinsheet, $group->id);
            if (count($questions) < 1) {
                $emptygroups[] = $group->number;
            }
        }
    }
    return $emptygroups;
}

/**
 *
 * @param unknown_type $scannedpage
 * @param unknown_type $corners
 */
function signinsheets_save_page_corners($scannedpage, $corners) {
    global $DB;

    $position = 0;
    if ($existingcorners = $DB->get_records('signinsheets_page_corners', array('scannedpageid' => $scannedpage->id), 'position')) {
        foreach ($existingcorners as $corner) {
            $corner->x = $corners[$position]->x;
            $corner->y = $corners[$position++]->y;
            $DB->update_record('signinsheets_page_corners', $corner);
        }

    } else {
        foreach ($corners as $corner) {
            unset($corner->blank);
            $corner->position = $position++;
            $corner->scannedpageid = $scannedpage->id;
            $DB->insert_record('signinsheets_page_corners', $corner);
        }
    }
}


/**
 *
 * @param unknown_type $page
 */
function signinsheets_delete_scanned_page($page, $context) {
    global $DB;

    $resultid = $page->resultid;
    $fs = get_file_storage();

    // Delete the scanned page.
    $DB->delete_records('signinsheets_scanned_pages', array('id' => $page->id));
    // Delete the choices made on the page.
    $DB->delete_records('signinsheets_choices', array('scannedpageid' => $page->id));
    // Delete the corner coordinates.
    $DB->delete_records('signinsheets_page_corners', array('scannedpageid' => $page->id));

    // If there is no scannedpage for the result anymore, we also delete the result.
    if ($resultid && !$DB->get_records('signinsheets_scanned_pages', array('resultid' => $resultid))) {
        // Delete the result.
        $DB->delete_records('signinsheets_results', array('id' => $resultid));
    }

    // JZ: also delete the image files associated with the deleted page.
    if ($page->filename && $file = $fs->get_file($context->id, 'mod_attendance', 'imagefiles', 0, '/', $page->filename)) {
        $file->delete();
    }
    if ($page->warningfilename &&
        $file = $fs->get_file($context->id, 'mod_attendance', 'imagefiles', 0, '/', $page->warningfilename)) {

        $file->delete();
    }
}

/**
 *
 * @param unknown_type $page
 */
function signinsheets_delete_scanned_p_page($page, $context) {
    global $DB;

    $fs = get_file_storage();

    // Delete the scanned participants page.
    $DB->delete_records('signinsheets_scanned_p_pages', array('id' => $page->id));
    // Delete the choices made on the page.
    $DB->delete_records('signinsheets_p_choices', array('scannedppageid' => $page->id));

    // JZ: also delete the image files associated with the deleted page.
    if ($page->filename && $file = $fs->get_file($context->id, 'mod_attendance', 'imagefiles', 0, '/', $page->filename)) {
        $file->delete();
    }
}



/**
 * Delete an signinsheet result, including the questions_usage_by_activity corresponding to it.
 *
 * @param mixed $attempt an integer attempt id or an attempt object
 *      (row of the signinsheets_results table).
 * @param object $signinsheet the signinsheet object.
 */
function signinsheets_delete_result($resultid, $context) {
    global $DB;

    if ($result = $DB->get_record('signinsheets_results', array('id' => $resultid))) {

        // First delete the result itself.
        $DB->delete_records('signinsheets_results', array('id' => $result->id));

        // Now we delete all scanned pages that refer to the result.
        $scannedpages = $DB->get_records_sql("
                SELECT *
                  FROM {signinsheets_scanned_pages}
                 WHERE resultid = :resultid", array('resultid' => $result->id));

        foreach ($scannedpages as $page) {
            signinsheets_delete_scanned_page($page, $context);
        }

        // Finally, delete the question usage that belongs to the result.
        if ($result->usageid) {
            question_engine::delete_questions_usage_by_activity($result->usageid);
        }
    }
}



/**
 * Returns info about the JS module used by signinsheetzes.
 *
 * @return multitype:string multitype:string  multitype:multitype:string
 */
function signinsheets_get_js_module() {
    global $PAGE;
    return array(
            'name' => 'mod_attendance',
            'fullpath' => '/mod/attendance/module.js',
            'requires' => array('base', 'dom', 'event-delegate', 'event-key',
                    'core_question_engine'),
            'strings' => array(
                    array('timesup', 'attendance'),
                    array('functiondisabledbysecuremode', 'attendance'),
                    array('flagged', 'question'),
            ),
    );
}


/**
 * @deprecated User identification is now set in admin settings.
 */
function signinsheets_load_useridentification() {
    return;
}

/**
 * Returns the number of pages in a signinsheet layout
 *
 * @param string $layout The string representing the signinsheet layout. Always ends in ,0
 * @return int The number of pages in the signinsheet.
 */
function signinsheets_number_of_pages($layout) {
    return substr_count(',' . $layout, ',0');
}

/**
 * Extends first object with member data of the second
 *
 * @param unknown_type $first
 * @param unknown_type $second
 */
function signinsheets_extend_object (&$first, &$second) {

    foreach ($second as $key => $value) {
        if (empty($first->$key)) {
            $first->$key = $value;
        }
    }

}

/**
 * Returns the group object for a given signinsheet and group number (1,2,3...). Adds a
 * new group if the group does not exist.
 *
 * @param unknown_type $signinsheet
 * @param unknown_type $groupnumber
 * @return Ambigous <mixed, boolean, unknown>
 */
function signinsheets_get_group($signinsheet, $groupnumber) {
    global $DB;

    if (!$signinsheetgroup = $DB->get_record('signinsheets_groups',
                                              array('signinsheetid' => $signinsheet->id, 'number' => $groupnumber))) {
        if ($groupnumber > 0 && $groupnumber <= $signinsheet->numgroups) {
            $signinsheetgroup = signinsheets_add_group( $signinsheet->id, $groupnumber);
        }
    }
    return $signinsheetgroup;
}

/**
 * Adds a new group with a given group number to a given signinsheet.
 *
 * @param object $signinsheet the data that came from the form.
 * @param int groupnumber The number of the group to add.
 * @return mixed the id of the new instance on success,
 *          false or a string error message on failure.
 */
function signinsheets_add_group($signinsheetid, $groupnumber) {
    GLOBAL $DB;

    $signinsheetgroup = new StdClass();
    $signinsheetgroup->signinsheetid = $signinsheetid;
    $signinsheetgroup->number = $groupnumber;

    // Note: numberofpages and templateusageid will be filled later.

    // Try to store it in the database.
    if (!$signinsheetgroup->id = $DB->insert_record('signinsheets_groups', $signinsheetgroup)) {
        return false;
    }

    return $signinsheetgroup;
}

/**
 * Checks whether any list of participants have been created for a given signinsheet.
 *
 * @param object $attendance
 * @return boolean
 */
function signinsheets_partlist_created($attendance) {
    global $DB;

    return $DB->count_records('attendance_ss_p_lists', array('attendanceid' => $attendance->id)) > 0;
}


/**
 * An extension of question_display_options that includes the extra options used
 * by the signinsheet.
 *
 * @copyright     2015 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_signinsheets_display_options extends question_display_options {
    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the result.
     */
    public $responses = true;

    /**
     * @var boolean if this is false, then the student cannot see the scanned answer forms
     */
    public $sheetfeedback = false;

    /**
     * @var boolean if this is false, then the student cannot see any markings in the scanned answer forms.
     */
    public $gradedsheetfeedback = false;

    /**
     * Set up the various options from the signinsheet settings, and a time constant.
     * @param object $signinsheet the signinsheet settings.
     * @param int $one of the {@link DURING}, {@link IMMEDIATELY_AFTER},
     * {@link LATER_WHILE_OPEN} or {@link AFTER_CLOSE} constants.
     * @return mod_signinsheets_display_options set up appropriately.
     */
    public static function make_from_signinsheet($signinsheet) {
        $options = new self();

        $options->attempt = self::extract($signinsheet->review, signinsheets_REVIEW_ATTEMPT);
        $options->marks = self::extract($signinsheet->review, signinsheets_REVIEW_MARKS) ? question_display_options::MARK_AND_MAX : question_display_options::HIDDEN;
        $options->correctness = self::extract($signinsheet->review, signinsheets_REVIEW_CORRECTNESS);
        $options->feedback = self::extract($signinsheet->review, signinsheets_REVIEW_SPECIFICFEEDBACK);
        $options->generalfeedback = self::extract($signinsheet->review, signinsheets_REVIEW_GENERALFEEDBACK);
        $options->rightanswer = self::extract($signinsheet->review, signinsheets_REVIEW_RIGHTANSWER);
        $options->sheetfeedback = self::extract($signinsheet->review, signinsheets_REVIEW_SHEET);
        $options->gradedsheetfeedback = self::extract($signinsheet->review, signinsheets_REVIEW_GRADEDSHEET);

        $options->numpartscorrect = $options->feedback;

        if (property_exists($signinsheet, 'decimalpoints')) {
            $options->markdp = $signinsheet->decimalpoints;
        }

        // We never want to see any flags.
        $options->flags = question_display_options::HIDDEN;

        return $options;
    }

    protected static function extract($bitmask, $bit, $whenset = self::VISIBLE, $whennotset = self::HIDDEN) {
        if ($bitmask & $bit) {
            return $whenset;
        } else {
            return $whennotset;
        }
    }
}


/**
 * The appropriate mod_signinsheets_display_options object for this result at this
 * signinsheet right now.
 *
 * @param object $signinsheet the signinsheet instance.
 * @param object $result the result in question.
 * @param $context the signinsheet context.
 *
 * @return mod_signinsheets_display_options
 */
function signinsheets_get_review_options($signinsheet, $result, $context) {

    $options = mod_signinsheets_display_options::make_from_signinsheet($signinsheet);

    $options->readonly = true;

    if (!empty($result->id)) {
        $options->questionreviewlink = new moodle_url('/mod/signinsheet/reviewquestion.php',
                array('resultid' => $result->id));
    }

    if (!is_null($context) &&
            has_capability('mod/signinsheet:viewreports', $context) &&
            has_capability('moodle/grade:viewhidden', $context)) {

        // The teacher should be shown everything.
        $options->attempt = question_display_options::VISIBLE;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->correctness = question_display_options::VISIBLE;
        $options->feedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->sheetfeedback = question_display_options::VISIBLE;
        $options->gradedsheetfeedback = question_display_options::VISIBLE;

        // Show a link to the comment box only for closed attempts.
        if (!empty($result->id) && $result->timefinish &&
                !is_null($context) && has_capability('mod/signinsheet:grade', $context)) {
            $options->manualcomment = question_display_options::VISIBLE;
            $options->manualcommentlink = new moodle_url('/mod/signinsheet/comment.php',
                    array('resultid' => $result->id));
        }
    }
    return $options;
}


/**
 * Combines the review options from a number of different signinsheet attempts.
 * Returns an array of two ojects, so the suggested way of calling this
 * funciton is:
 * list($someoptions, $alloptions) = signinsheets_get_combined_reviewoptions(...)
 *
 * @param object $signinsheet the signinsheet instance.
 * @param array $attempts an array of attempt objects.
 * @param $context the roles and permissions context,
 *          normally the context for the signinsheet module instance.
 *
 * @return array of two options objects, one showing which options are true for
 *          at least one of the attempts, the other showing which options are true
 *          for all attempts.
 */
function signinsheets_get_combined_reviewoptions($signinsheet) {
    $fields = array('feedback', 'generalfeedback', 'rightanswer');
    $someoptions = new stdClass();
    $alloptions = new stdClass();
    foreach ($fields as $field) {
        $someoptions->$field = false;
        $alloptions->$field = true;
    }
    $someoptions->marks = question_display_options::HIDDEN;
    $alloptions->marks = question_display_options::MARK_AND_MAX;

    $attemptoptions = mod_signinsheets_display_options::make_from_signinsheet($signinsheet);
    foreach ($fields as $field) {
        $someoptions->$field = $someoptions->$field || $attemptoptions->$field;
        $alloptions->$field = $alloptions->$field && $attemptoptions->$field;
    }
    $someoptions->marks = max($someoptions->marks, $attemptoptions->marks);
    $alloptions->marks = min($alloptions->marks, $attemptoptions->marks);

    return array($someoptions, $alloptions);
}


/**
 * Deletes the PDF forms of an signinsheet.
 *
 * @param object $attendance
 */
function signinsheets_delete_pdf_forms($attendance) {
    global $DB;

    $fs = get_file_storage();

    // If the signinsheet has just been created then there is no cmid.
    if (isset($signinsheet->cmid)) {
        $context = context_module::instance($signinsheet->cmid);

        // Delete PDF documents.
        $files = $fs->get_area_files($context->id, 'mod_attendance', 'participants');
        foreach ($files as $file) {
            $file->delete();
        }
    }
    // Delete the file names in the signinsheet groups.
    $DB->set_field('signinsheets_groups', 'questionfilename', null, array('attendanceid' => $attendance->id));
    $DB->set_field('signinsheets_groups', 'answerfilename', null, array('attendanceid' => $attendance->id));
    $DB->set_field('signinsheets_groups', 'correctionfilename', null, array('attendanceid' => $attendance->id));

    // Set signinsheet->docscreated to 0.
    $signinsheet->docscreated = 0;
    $DB->set_field('signinsheet', 'docscreated', 0, array('id' => $attendance->id));
    return $signinsheet;
}


/**
 * Prints a list of participants to Stdout.
 *
 * @param object $attendance
 * @param unknown_type $coursecontext
 * @param unknown_type $systemcontext
 */
function signinsheets_print_partlist($attendance, &$coursecontext, &$systemcontext) {
    global $CFG, $COURSE, $DB, $OUTPUT;
    signinsheets_load_useridentification();
    $signinsheetconfig = get_config('attendance');

    if (!$course = $DB->get_record('course', array('id' => $coursecontext->instanceid))) {
        print_error('invalid course');
    }
    $pagesize = optional_param('pagesize', NUMBERS_PER_PAGE, PARAM_INT);
    $checkoption = optional_param('checkoption', 0, PARAM_INT);
    $listid = optional_param('listid', '', PARAM_INT);
    $lists = $DB->get_records_sql("
            SELECT id, number, name, attendanceid, sessionid
              FROM {attendance_ss_p_lists}
             WHERE attendanceid = :attendanceid
          ORDER BY number ASC",
            array('attendanceid' => $attendance->id));

    // First get roleids for students from leagcy.
    if (!$roles = get_roles_with_capability('mod/attendance_signinsheet:attempt', CAP_ALLOW, $systemcontext)) {
        print_error("No roles with capability 'mod/attendance_signinsheet:attempt' defined in system context");
    }

    $roleids = array();
    foreach ($roles as $role) {
        $roleids[] = $role->id;
    }

    list($csql, $cparams) = $DB->get_in_or_equal($coursecontext->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'ctx');
    list($rsql, $rparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
    $params = array_merge($cparams, $rparams);

    $sql = "SELECT p.id, p.userid, p.listid, u.".$signinsheetconfig->ID_field.", u.firstname, u.lastname,
                   u.alternatename, u.middlename, u.firstnamephonetic, u.lastnamephonetic, u.picture, p.checked
              FROM {attendance_ss_participants} p,
                   {attendance_ss_p_lists} pl,
                   {user} u,
                   {role_assignments} ra
             WHERE p.listid = pl.id
               AND p.userid = u.id
               AND ra.userid=u.id
               AND pl.attendanceid = :attendanceid
               AND ra.contextid $csql
               AND ra.roleid $rsql";

    $params['attendanceid'] = $attendance->id;
    if (!empty($listid)) {
        $sql .= " AND p.listid = :listid";
        $params['listid'] = $listid;
    }

    $countsql = "SELECT COUNT(*)
                   FROM {attendance_ss_participants} p,
                        {attendance_ss_p_lists} pl,
                        {user} u
                  WHERE p.listid = pl.id
                    AND p.userid = u.id
                    AND pl.attendanceid = :attendanceid";

    $cparams = array('attendanceid' => $attendance->id);
    if (!empty($listid)) {
        $countsql .= " AND p.listid = :listid";
        $cparams['listid'] = $listid;
    }

    require_once($CFG->libdir . '/tablelib.php');

    $tableparams = array('q' => $signinsheet->id,
            'mode' => 'attendances',
            'listid' => $listid,
            'pagesize' => $pagesize,
            'strreallydel' => '');

    $table = new signinsheets_partlist_table('mod-attendance-participants', 'signinsheets.php', $tableparams);

    // Define table columns.
    $tablecolumns = array('checkbox', 'picture', 'fullname', $signinsheetconfig->ID_field, 'number', 'attempt', 'checked');
    $tableheaders = array('<input type="checkbox" name="toggle" class="select-all-checkbox"/>',
            '', get_string('fullname'), get_string($signinsheetconfig->ID_field), get_string('participantslist', 'attendance'),
            get_string('attemptexists', 'attendance'), get_string('present', 'attendance'));

    $table->define_columns($tablecolumns);
    $table->define_headers($tableheaders);
    $table->define_baseurl($CFG->wwwroot.'/mod/attendance/signinsheets.php?mode=attendances&amp;att=' .
            $signinsheet->id . '&amp;checkoption=' . $checkoption . '&amp;pagesize=' . $pagesize. '&amp;listid=' . $listid);

    $table->sortable(true);
    $table->no_sorting('attempt');
    $table->no_sorting('checkbox');
    if (!empty($listid)) {
        $table->no_sorting('listid');
    }
    $table->set_attribute('cellpadding', '2');
    $table->set_attribute('id', 'participants-table');
    $table->set_attribute('class', 'generaltable generalbox');

    // Start working -- this is necessary as soon as the niceties are over.
    $table->setup();

    // Add extra limits due to initials bar.
    if (!empty($countsql)) {
        $totalinitials = $DB->count_records_sql($countsql, $cparams);
        // Add extra limits due to initials bar.
        list($ttest, $tparams) = $table->get_sql_where();

        if (!empty($ttest) && (empty($checkoption) or $checkoption == 0)) {
            $sql .= ' AND ' . $ttest;
            $params = array_merge($params, $tparams);

            $countsql .= ' AND ' . $ttest;
            $cparams = array_merge($cparams, $tparams);
        }
        $total  = $DB->count_records_sql($countsql, $cparams);
    }

    if ($sort = $table->get_sql_sort()) {
        $sql .= ' ORDER BY ' . $sort;
    } else {
        $sql .= ' ORDER BY u.lastname, u.firstname';
    }

    $table->initialbars($totalinitials > 20);
    // Special settings for checkoption: show all entries on one page.
    if (!empty($checkoption) and $checkoption > 0) {
        $pagesize = $total;
        $table->pagesize($pagesize, $total);
        $participants = $DB->get_records_sql($sql, $params);
    } else {
        $table->pagesize($pagesize, $total);
        $participants = $DB->get_records_sql($sql, $params, $table->get_page_start(), $table->get_page_size());
    }

    $strreallydel  = addslashes(get_string('deletepartcheck', 'attendance'));

    $sql = "SELECT COUNT(*)
              FROM {signinsheets_results}
             WHERE userid = :userid
               AND attendanceid = :attendanceid
               AND status = 'complete'";
    $params = array('attendanceid' => $attendance->id);
    if ($participants) {
        foreach ($participants as $participant) {
            $user = $DB->get_record('user', array('id' => $participant->userid));
            $picture = $OUTPUT->user_picture($user, array('courseid' => $coursecontext->instanceid));

            $userlink = '<a href="'.$CFG->wwwroot.'/user/view.php?id=' . $participant->userid .
                '&amp;course=' . $coursecontext->instanceid.'">'.fullname($participant).'</a>';
            $params['userid'] = $participant->userid;
            if ($DB->count_records_sql($sql, $params) > 0) {
                $attempt = true;
            } else {
                $attempt = false;
            }
            $row = array(
                    '<input type="checkbox" name="participantid[]" value="'.$participant->id.'"  class="select-multiple-checkbox"/>',
                    $picture,
                    $userlink,
                    $participant->{$signinsheetconfig->ID_field},
                    $lists[$participant->listid]->name,
                    $attempt ? "<img src=\"$CFG->wwwroot/mod/attendance/pix/tick.gif\" alt=\"" .
                    get_string('attemptexists', 'attendance') . "\">" : "<img src=\"$CFG->wwwroot/mod/attendance/pix/cross.gif\" alt=\"" .
                    get_string('noattemptexists', 'attendance') . "\">",
                    $participant->checked ? "<img src=\"$CFG->wwwroot/mod/attendance/pix/tick.gif\" alt=\"" .
                    get_string('ischecked', 'attendance') . "\">" : "<img src=\"$CFG->wwwroot/mod/attendance/pix/cross.gif\" alt=\"" .
                    get_string('isnotchecked', 'attendance') . "\">"
                    );
            switch ($checkoption) {
                case '0':
                    $table->add_data($row);
                    break;
                case '1':
                    if (!$attempt and $participant->checked) {
                        $table->add_data($row);
                    }
                    break;
                case '2':
                    if ($attempt and !$participant->checked) {
                        $table->add_data($row);
                    }
                    break;
            }
        }
    } else {
        // Print table.
        $table->print_initials_bar();
    }
    $table->finish_html();

    // Print "Select all" etc.
    echo '<center>';

    if (!empty($participants)) {
        echo '<form id="downloadoptions" action="signinsheets.php" method="get">';
        echo '<input type="hidden" name="att" value="' . $signinsheet->id . '" />';
        echo '<input type="hidden" name="mode" value="attendances" />';
        echo '<input type="hidden" name="pagesize" value="' . $pagesize . '" />';
        echo '<input type="hidden" name="listid" value="' . $listid . '" />';
        echo '<table class="boxaligncenter"><tr><td>';
        $options = array(
                'Excel' => get_string('excelformat', 'attendance'),
                'ODS' => get_string('odsformat', 'attendance'),
                'CSV' => get_string('csvformat', 'attendance')
        );
        print_string('downloadresultsas', 'signinsheet');
        echo "</td><td>";
        echo html_writer::select($options, 'download', '', false);
        echo '<button type="submit" class="btn btn-primary" >' . get_string('go') . '</button>';
        echo '<script type="text/javascript">'."\n<!--\n".'document.getElementById("noscriptmenuaction").style.display = "none";'.
            "\n-->\n".'</script>';
        echo "</td>\n";
        echo "<td>";
        echo "</td>\n";
        echo '</tr></table></form>';
    }

    // Print display options.
    echo '<div class="controls">';
    echo '<form id="options" action="signinsheets.php" method="get">';
    echo '<center>';
    echo '<p>'.get_string('displayoptions', 'quiz').': </p>';
    echo '<input type="hidden" name="att" value="' . $signinsheet->id . '" />';
    echo '<input type="hidden" name="mode" value="attendances" />';
    echo '<input type="hidden" name="listid" value="'.$listid.'" />';
    echo '<table id="participant-options" class="boxaligncenter">';
    echo '<tr align="left">';
    echo '<td><label for="pagesize">'.get_string('pagesizeparts', 'attendance').'</label></td>';
    echo '<td><input type="text" id="pagesize" name="pagesize" size="3" value="'.$pagesize.'" /></td>';
    echo '</tr>';
    echo '<tr align="left">';
    echo '<td colspan="2">';

    $options = array(0 => get_string('showallparts', 'signinsheet', $total));
    if ($course->id != SITEID) {
        $options[1] = get_string('showmissingattemptonly', 'attendance');
        $options[2] = get_string('showmissingcheckonly', 'attendance');
    }

    echo html_writer::select($options, 'checkoption', $checkoption);
    echo '</td></tr>';
    echo '<tr><td colspan="2" align="center">';
    echo '<button type="submit" class="btn btn-secondary" >' .get_string('go'). '</button>';
    echo '</td></tr></table>';
    echo '</center>';
    echo '</form>';
    echo '</div>';
    echo "\n";
}

/**
 * Serves a list of participants as a file.
 *
 * @param object $attendance_ss
 * @param unknown_type $fileformat
 * @param unknown_type $coursecontext
 * @param unknown_type $systemcontext
 */
function signinsheets_download_partlist($attendance_ss, $fileformat, &$coursecontext, &$systemcontext) {
    global $CFG, $DB, $COURSE;

    signinsheets_load_useridentification();
    $signinsheetconfig = get_config('attendance');

    $filename = clean_filename(get_string('participants', 'attendance') . $signinsheet->id);

    // First get roleids for students from leagcy.
    if (!$roles = get_roles_with_capability('mod/attendance_signinsheet:attempt', CAP_ALLOW, $systemcontext)) {
        print_error("No roles with capability 'mod/attendance_signinsheet:attempt' defined in system context");
    }

    $roleids = array();
    foreach ($roles as $role) {
        $roleids[] = $role->id;
    }

    list($csql, $cparams) = $DB->get_in_or_equal($coursecontext->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'ctx');
    list($rsql, $rparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
    $params = array_merge($cparams, $rparams);

    $sql = "SELECT p.id, p.userid, p.listid, u." . $signinsheetconfig->ID_field . ", u.firstname, u.lastname,
                   u.alternatename, u.middlename, u.firstnamephonetic, u.lastnamephonetic,
                   u.picture, p.checked
             FROM {attendance_ss_participants} p,
                  {attendance_ss_p_lists} pl,
                  {user} u,
                  {role_assignments} ra
            WHERE p.listid = pl.id
              AND p.userid = u.id
              AND ra.userid=u.id
              AND pl.attendanceid = :attendanceid
              AND ra.contextid $csql
              AND ra.roleid $rsql";

    $params['attendanceid'] = $attendance->id;

    // Define table headers.
    $tableheaders = array(get_string('fullname'),
                          get_string($signinsheetconfig->ID_field),
                          get_string('participantslist', 'attendance'),
                          get_string('attemptexists', 'attendance'),
                          get_string('present', 'attendance'));

    if ($fileformat == 'ODS') {
        require_once("$CFG->libdir/odslib.class.php");

        $filename .= ".ods";
        // Creating a workbook.
        $workbook = new MoodleODSWorkbook("-");
        // Sending HTTP headers.
        $workbook->send($filename);
        // Creating the first worksheet.
        $sheettitle = get_string('participants', 'attendance');
        $myxls = $workbook->add_worksheet($sheettitle);
        // Format types.
        $format = $workbook->add_format();
        $format->set_bold(0);
        $formatbc = $workbook->add_format();
        $formatbc->set_bold(1);
        $formatbc->set_align('center');
        $formatb = $workbook->add_format();
        $formatb->set_bold(1);
        $formaty = $workbook->add_format();
        $formaty->set_bg_color('yellow');
        $formatc = $workbook->add_format();
        $formatc->set_align('center');
        $formatr = $workbook->add_format();
        $formatr->set_bold(1);
        $formatr->set_color('red');
        $formatr->set_align('center');
        $formatg = $workbook->add_format();
        $formatg->set_bold(1);
        $formatg->set_color('green');
        $formatg->set_align('center');

        // Print worksheet headers.
        $colnum = 0;
        foreach ($tableheaders as $item) {
            $myxls->write(0, $colnum, $item, $formatbc);
            $colnum++;
        }
        $rownum = 1;
    } else if ($fileformat == 'Excel') {
        require_once("$CFG->libdir/excellib.class.php");

        $filename .= ".xls";
        // Creating a workbook.
        $workbook = new MoodleExcelWorkbook("-");
        // Sending HTTP headers.
        $workbook->send($filename);
        // Creating the first worksheet.
        $sheettitle = get_string('participants', 'attendance');
        $myxls = $workbook->add_worksheet($sheettitle);
        // Format types.
        $format = $workbook->add_format();
        $format->set_bold(0);
        $formatbc = $workbook->add_format();
        $formatbc->set_bold(1);
        $formatbc->set_align('center');
        $formatb = new StdClass();
        $formatb = $workbook->add_format();
        $formatb->set_bold(1);
        $formaty = $workbook->add_format();
        $formaty->set_bg_color('yellow');
        $formatc = $workbook->add_format();
        $formatc->set_align('center');
        $formatr = $workbook->add_format();
        $formatr->set_bold(1);
        $formatr->set_color('red');
        $formatr->set_align('center');
        $formatg = $workbook->add_format();
        $formatg->set_bold(1);
        $formatg->set_color('green');
        $formatg->set_align('center');

        // Print worksheet headers.
        $colnum = 0;
        foreach ($tableheaders as $item) {
            $myxls->write(0, $colnum, $item, $formatbc);
            $colnum++;
        }
        $rownum = 1;
    } else if ($fileformat == 'CSV') {
        $filename .= ".csv";

        header("Content-Type: application/download\n");
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header("Expires: 0");
        header("Cache-Control: must-revalidate,post-check=0,pre-check=0");
        header("Pragma: public");

        $headers = implode(", ", $tableheaders);

        echo $headers . " \n";
    }

    $lists = $DB->get_records('attendance_ss_p_lists', array('attendance_ssid' => $attendance_ss->id));
    $participants = $DB->get_records_sql($sql, $params);
    if ($participants) {
        foreach ($participants as $participant) {
            $userid = $participant->userid;
            $attempt = false;
            $sql = "SELECT COUNT(*)
                      FROM {signinsheets_results}
                     WHERE userid = :userid
                       AND attendanceid = :attendanceid
                       AND status = 'complete'";
            if ($DB->count_records_sql($sql, array('userid' => $userid, 'attendanceid' => $attendance->id)) > 0) {
                $attempt = true;
            }
            $row = array(
                    fullname($participant),
                    $participant->{$signinsheetconfig->ID_field},
                    $lists[$participant->listid]->name,
                    $attempt ? get_string('yes') : get_string('no'),
                    $participant->checked ? get_string('yes') : get_string('no')
                    );
            if ($fileformat == 'Excel' or $fileformat == 'ODS') {
                $colnum = 0;
                foreach ($row as $item) {
                    $myxls->write($rownum, $colnum, $item, $format);
                    $colnum++;
                }
                $rownum++;
            } else if ($fileformat == 'CSV') {
                $text = implode(", ", $row);
                echo $text . "\n";
            }
        }
    }

    if ($fileformat == 'Excel' or $fileformat == 'ODS') {
        $workbook->close();
    }
    exit;
}

