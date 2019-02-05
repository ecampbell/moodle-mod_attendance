<?php
// This file is part of mod_signinsheet for Moodle - http://moodle.org/
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
 * @subpackage    signinsheet
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

class signinsheet_question_usage_by_activity extends question_usage_by_activity {

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

        $quba = new signinsheet_question_usage_by_activity($record->component,
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

function signinsheet_make_questions_usage_by_activity($component, $context) {
    return new signinsheet_question_usage_by_activity($component, $context);
}

/**
 * Load a {@link question_usage_by_activity} from the database, including
 * all its {@link question_attempt}s and all their steps.
 * @param int $qubaid the id of the usage to load.
 * @param question_usage_by_activity the usage that was loaded.
 */
function signinsheet_load_questions_usage_by_activity($qubaid) {
    global $DB;

    $records = $DB->get_recordset_sql("
            SELECT quba.id AS qubaid,
                   quba.contextid,
                   quba.component,
                   quba.preferredbehaviour,
                   qa.id AS questionattemptid,
                   qa.questionusageid,
                   qa.slot,
                   qa.behaviour,
                   qa.questionid,
                   qa.variant,
                   qa.maxmark,
                   qa.minfraction,
                   qa.maxfraction,
                   qa.flagged,
                   qa.questionsummary,
                   qa.rightanswer,
                   qa.responsesummary,
                   qa.timemodified,
                   qas.id AS attemptstepid,
                   qas.sequencenumber,
                   qas.state,
                   qas.fraction,
                   qas.timecreated,
                   qas.userid,
                   qasd.name,
                   qasd.value
              FROM {question_usages}            quba
         LEFT JOIN {question_attempts}          qa   ON qa.questionusageid    = quba.id
         LEFT JOIN {question_attempt_steps}     qas  ON qas.questionattemptid = qa.id
         LEFT JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid    = qas.id
            WHERE  quba.id = :qubaid
          ORDER BY qa.slot,
                   qas.sequencenumber
            ", array('qubaid' => $qubaid));

    if (!$records->valid()) {
        throw new coding_exception('Failed to load questions_usage_by_activity ' . $qubaid);
    }

    $quba = signinsheet_question_usage_by_activity::load_from_records($records, $qubaid);
    $records->close();

    return $quba;
}

/**
 *
 * @param int $signinsheet
 * @param int $groupid
 * @return string
 */
function signinsheet_get_group_question_ids($signinsheet, $groupid = 0) {
    global $DB;

    if (!$groupid) {
        $groupid = $signinsheet->groupid;
    }

    // This query only makes sense if it is restricted to a offline group.
    if (!$groupid) {
        return '';
    }

    $sql = "SELECT questionid
              FROM {signinsheet_group_questions}
             WHERE signinsheetid = :signinsheetid
               AND offlinegroupid = :offlinegroupid
          ORDER BY slot ASC ";

    $params = array('signinsheetid' => $signinsheet->id, 'offlinegroupid' => $groupid);
    $questionids = $DB->get_fieldset_sql($sql, $params);

    return $questionids;
}


/**
 *
 * @param mixed $signinsheet The signinsheet
 * @return array returns an array of offline group numbers
 */
function signinsheet_get_empty_groups($signinsheet) {
    global $DB;

    $emptygroups = array();

    if ($groups = $DB->get_records('signinsheet_groups',
                                   array('signinsheetid' => $signinsheet->id), 'number', '*', 0, $signinsheet->numgroups)) {
        foreach ($groups as $group) {
            $questions = signinsheet_get_group_question_ids($signinsheet, $group->id);
            if (count($questions) < 1) {
                $emptygroups[] = $group->number;
            }
        }
    }
    return $emptygroups;
}


/**
 * Get the slot for a question with a particular id.
 * @param object $signinsheet the signinsheet settings.
 * @param int $questionid the of a question in the signinsheet.
 * @return int the corresponding slot. Null if the question is not in the signinsheet.
 */
function signinsheet_get_slot_for_question($signinsheet, $group, $questionid) {
    $questionids = signinsheet_get_group_question_ids($signinsheet, $group->id);
    foreach ($questionids as $key => $id) {
        if ($id == $questionid) {
            return $key + 1;
        }
    }
    return null;
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * Does not return. Throws an exception if the question cannot be used.
 * @param int $questionid The id of the question.
 */
function signinsheet_require_question_use($questionid) {
    global $DB;
    $question = $DB->get_record('question', array('id' => $questionid), '*', MUST_EXIST);
    question_require_capability_on($question, 'use');
}

/**
 * Add a question to a signinsheet
 *
 * Adds a question to a signinsheet by updating $signinsheet as well as the
 * signinsheet and signinsheet_slots tables. It also adds a page break if required.
 * @param int $questionid The id of the question to be added
 * @param object $signinsheet The extended signinsheet object as used by edit.php
 *      This is updated by this function
 * @param int $page Which page in signinsheet to add the question on. If 0 (default),
 *      add at the end
 * @param float $maxmark The maximum mark to set for this question. (Optional,
 *      defaults to question.defaultmark.
 * @return bool false if the question was already in the signinsheet
 */
function signinsheet_add_signinsheet_question($questionid, $signinsheet, $page = 0, $maxmark = null) {
    global $DB;

    if (signinsheet_has_scanned_pages($signinsheet->id)) {
        return false;
    }

    $slots = $DB->get_records('signinsheet_group_questions',
            array('signinsheetid' => $signinsheet->id, 'offlinegroupid' => $signinsheet->groupid),
            'slot', 'questionid, slot, page, id');
    if (array_key_exists($questionid, $slots)) {
        return false;
    }

    $trans = $DB->start_delegated_transaction();

    $maxpage = 1;
    $numonlastpage = 0;
    foreach ($slots as $slot) {
        if ($slot->page > $maxpage) {
            $maxpage = $slot->page;
            $numonlastpage = 1;
        } else {
            $numonlastpage += 1;
        }
    }

    // Add the new question instance.
    $slot = new stdClass();
    $slot->signinsheetid = $signinsheet->id;
    $slot->offlinegroupid = $signinsheet->groupid;
    $slot->questionid = $questionid;

    if ($maxmark !== null) {
        $slot->maxmark = $maxmark;
    } else {
        $slot->maxmark = $DB->get_field('question', 'defaultmark', array('id' => $questionid));
    }

    if (is_int($page) && $page >= 1) {
        // Adding on a given page.
        $lastslotbefore = 0;
        foreach (array_reverse($slots) as $otherslot) {
            if ($otherslot->page > $page) {
                // Increase the slot number of the other slot.
                $DB->set_field('signinsheet_group_questions', 'slot', $otherslot->slot + 1, array('id' => $otherslot->id));
            } else {
                $lastslotbefore = $otherslot->slot;
                break;
            }
        }
        $slot->slot = $lastslotbefore + 1;
        $slot->page = min($page, $maxpage + 1);

    } else {
        $lastslot = end($slots);
        if ($lastslot) {
            $slot->slot = $lastslot->slot + 1;
        } else {
            $slot->slot = 1;
        }
        if ($signinsheet->questionsperpage && $numonlastpage >= $signinsheet->questionsperpage) {
            $slot->page = $maxpage + 1;
        } else {
            $slot->page = $maxpage;
        }
    }

    $DB->insert_record('signinsheet_group_questions', $slot);
    $trans->allow_commit();
}

/**
 * returns the maximum number of questions in a set of offline groups
 *
 * @param unknown_type $signinsheet
 * @param unknown_type $groups
 * @return Ambigous <number, unknown>
 */
function signinsheet_get_maxquestions($signinsheet, $groups) {
    global $DB;

    $maxquestions = 0;
    foreach ($groups as $group) {

        $questionids = signinsheet_get_group_question_ids($signinsheet, $group->id);

        list($qsql, $params) = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);

        $numquestions = $DB->count_records_sql("SELECT COUNT(id) FROM {question} WHERE qtype <> 'description' AND id $qsql",
                                               $params);
        if ($numquestions > $maxquestions) {
            $maxquestions = $numquestions;
        }
    }
    return $maxquestions;
}

/**
 *
 * @param unknown_type $scannedpage
 * @param unknown_type $corners
 */
function signinsheet_save_page_corners($scannedpage, $corners) {
    global $DB;

    $position = 0;
    if ($existingcorners = $DB->get_records('signinsheet_page_corners', array('scannedpageid' => $scannedpage->id), 'position')) {
        foreach ($existingcorners as $corner) {
            $corner->x = $corners[$position]->x;
            $corner->y = $corners[$position++]->y;
            $DB->update_record('signinsheet_page_corners', $corner);
        }

    } else {
        foreach ($corners as $corner) {
            unset($corner->blank);
            $corner->position = $position++;
            $corner->scannedpageid = $scannedpage->id;
            $DB->insert_record('signinsheet_page_corners', $corner);
        }
    }
}

/**
 * returns the maximum number of answers in the group questions of an signinsheet
 * @param unknown_type $signinsheet
 * @return number
 */
function signinsheet_get_maxanswers($signinsheet, $groups = array()) {
    global $CFG, $DB;

    $groupids = array();
    foreach ($groups as $group) {
        $groupids[] = $group->id;
    }

    $sql = "SELECT DISTINCT(questionid)
              FROM {signinsheet_group_questions}
             WHERE signinsheetid = :signinsheetid
               AND questionid > 0";

    if (!empty($groupids)) {
        list($gsql, $params) = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
        $sql .= " AND offlinegroupid " . $gsql;
    } else {
        $params = array();
    }

    $params['signinsheetid'] = $signinsheet->id;

    $questionids = $DB->get_records_sql($sql, $params);
    $questionlist = array_keys($questionids);

    $counts = array();
    if (!empty($questionlist)) {
        foreach ($questionlist as $questionid) {
            $sql = "SELECT COUNT(id)
                      FROM {question_answers} qa
                     WHERE qa.question = :questionid
                    ";
            $params = array('questionid' => $questionid);
            $counts[] = $DB->count_records_sql($sql, $params);
        }
        return max($counts);
    } else {
        return 0;
    }
}


/**
 * Repaginate the questions in a signinsheet
 * @param int $signinsheetid the id of the signinsheet to repaginate.
 * @param int $slotsperpage number of items to put on each page. 0 means unlimited.
 */
function signinsheet_repaginate_questions($signinsheetid, $offlinegroupid, $slotsperpage) {
    global $DB;
    $trans = $DB->start_delegated_transaction();

    $slots = $DB->get_records('signinsheet_group_questions',
            array('signinsheetid' => $signinsheetid, 'offlinegroupid' => $offlinegroupid),
            'slot');

    $currentpage = 1;
    $slotsonthispage = 0;
    foreach ($slots as $slot) {
        if ($slotsonthispage && $slotsonthispage == $slotsperpage) {
            $currentpage += 1;
            $slotsonthispage = 0;
        }
        if ($slot->page != $currentpage) {
            $DB->set_field('signinsheet_group_questions', 'page', $currentpage,
                    array('id' => $slot->id));
        }
        $slotsonthispage += 1;
    }

    $trans->allow_commit();
}

/**
 * Re-paginates the signinsheet layout
 *
 * @return string         The new layout string
 * @param string $layout  The string representing the signinsheet layout.
 * @param integer $perpage The number of questions per page
 * @param boolean $shuffle Should the questions be reordered randomly?
 */
function signinsheet_shuffle_questions($questionids) {
    srand((float)microtime() * 1000000); // For php < 4.2.
    shuffle($questionids);
    return $questionids;
}

/**
 * returns true if there are scanned pages for an offline quiz.
 * @param int $signinsheetid
 */
function signinsheet_has_scanned_pages($signinsheetid) {
    global $CFG, $DB;

    $sql = "SELECT COUNT(id)
              FROM {signinsheet_scanned_pages}
             WHERE signinsheetid = :signinsheetid";
    $params = array('signinsheetid' => $signinsheetid);
    return $DB->count_records_sql($sql, $params) > 0;
}

/**
 *
 * @param unknown_type $page
 */
function signinsheet_delete_scanned_page($page, $context) {
    global $DB;

    $resultid = $page->resultid;
    $fs = get_file_storage();

    // Delete the scanned page.
    $DB->delete_records('signinsheet_scanned_pages', array('id' => $page->id));
    // Delete the choices made on the page.
    $DB->delete_records('signinsheet_choices', array('scannedpageid' => $page->id));
    // Delete the corner coordinates.
    $DB->delete_records('signinsheet_page_corners', array('scannedpageid' => $page->id));

    // If there is no scannedpage for the result anymore, we also delete the result.
    if ($resultid && !$DB->get_records('signinsheet_scanned_pages', array('resultid' => $resultid))) {
        // Delete the result.
        $DB->delete_records('signinsheet_results', array('id' => $resultid));
    }

    // JZ: also delete the image files associated with the deleted page.
    if ($page->filename && $file = $fs->get_file($context->id, 'mod_signinsheet', 'imagefiles', 0, '/', $page->filename)) {
        $file->delete();
    }
    if ($page->warningfilename &&
        $file = $fs->get_file($context->id, 'mod_signinsheet', 'imagefiles', 0, '/', $page->warningfilename)) {

        $file->delete();
    }
}

/**
 *
 * @param unknown_type $page
 */
function signinsheet_delete_scanned_p_page($page, $context) {
    global $DB;

    $fs = get_file_storage();

    // Delete the scanned participants page.
    $DB->delete_records('signinsheet_scanned_p_pages', array('id' => $page->id));
    // Delete the choices made on the page.
    $DB->delete_records('signinsheet_p_choices', array('scannedppageid' => $page->id));

    // JZ: also delete the image files associated with the deleted page.
    if ($page->filename && $file = $fs->get_file($context->id, 'mod_signinsheet', 'imagefiles', 0, '/', $page->filename)) {
        $file->delete();
    }
}

/**
 * returns the number of completed results for an offline quiz.
 * @param int $signinsheetid
 * @param int $courseid
 * @param boolean $onlystudents
 */
function signinsheet_completed_results($signinsheetid, $courseid, $onlystudents = false) {
    global $CFG, $DB;

    if ($onlystudents) {
        $coursecontext = context_course::instance($courseid);
        $contextids = $coursecontext->get_parent_context_ids(true);
        list($csql, $params) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
        $params['signinsheetid'] = $signinsheetid;

        $select = "SELECT COUNT(DISTINCT(u.id)) as counter
                     FROM {user} u
                     JOIN {role_assignments} ra ON ra.userid = u.id
                LEFT JOIN {signinsheet_results} qa
                           ON u.id = qa.userid
                          AND qa.signinsheetid = :signinsheetid
                          AND qa.status = 'complete'
                    WHERE ra.contextid $csql
                      AND qa.userid IS NOT NULL
        ";

        return $DB->count_records_sql($select, $params);
    } else {
        $params = array('signinsheetid' => $signinsheetid);
        return $DB->count_records_select('signinsheet_results', "signinsheetid = :signinsheetid AND status = 'complete'",
                                         $params, 'COUNT(id)');
    }
}

/**
 * Delete an signinsheet result, including the questions_usage_by_activity corresponding to it.
 *
 * @param mixed $attempt an integer attempt id or an attempt object
 *      (row of the signinsheet_results table).
 * @param object $signinsheet the signinsheet object.
 */
function signinsheet_delete_result($resultid, $context) {
    global $DB;

    if ($result = $DB->get_record('signinsheet_results', array('id' => $resultid))) {

        // First delete the result itself.
        $DB->delete_records('signinsheet_results', array('id' => $result->id));

        // Now we delete all scanned pages that refer to the result.
        $scannedpages = $DB->get_records_sql("
                SELECT *
                  FROM {signinsheet_scanned_pages}
                 WHERE resultid = :resultid", array('resultid' => $result->id));

        foreach ($scannedpages as $page) {
            signinsheet_delete_scanned_page($page, $context);
        }

        // Finally, delete the question usage that belongs to the result.
        if ($result->usageid) {
            question_engine::delete_questions_usage_by_activity($result->usageid);
        }
    }
}

/**
 * Save new maxgrade to a question instance
 *
 * Saves changes to the question grades in the signinsheet_group_questions table.
 * The grades of the questions in the group template qubas are also updated.
 * This function does not update 'sumgrades' in the signinsheet table.
 *
 * @param int $signinsheet  The signinsheet to update / add the instances for.
 * @param int $questionid  The id of the question
 * @param int grade    The maximal grade for the question
 */
function signinsheet_update_question_instance($signinsheet, $questionid, $grade) {
    global $DB;

    // First change the maxmark of the question in all offline quiz groups.
    $groupquestionids = $DB->get_fieldset_select('signinsheet_group_questions', 'id',
                    'signinsheetid = :signinsheetid AND questionid = :questionid',
                    array('signinsheetid' => $signinsheet->id, 'questionid' => $questionid));

    foreach ($groupquestionids as $groupquestionid) {
        $DB->set_field('signinsheet_group_questions', 'maxmark', $grade, array('id' => $groupquestionid));
    }

    $groups = $DB->get_records('signinsheet_groups', array('signinsheetid' => $signinsheet->id), 'number', '*', 0,
                $signinsheet->numgroups);

    // Now change the maxmark of the question instance in the template question usages of the signinsheet groups.
    foreach ($groups as $group) {

        if ($group->templateusageid) {
            $templateusage = question_engine::load_questions_usage_by_activity($group->templateusageid);
            $slots = $templateusage->get_slots();

            $slot = 0;
            foreach ($slots as $thisslot) {
                if ($templateusage->get_question($thisslot)->id == $questionid) {
                    $slot = $thisslot;
                    break;
                }
            }
            if ($slot) {
                // Update the grade in the template usage.
                question_engine::set_max_mark_in_attempts(new qubaid_list(array($group->templateusageid)), $slot, $grade);
            }
        }
    }

    // Now do the same for the qubas of the results of the offline quiz.
    if ($results = $DB->get_records('signinsheet_results', array('signinsheetid' => $signinsheet->id))) {
        foreach ($results as $result) {
            if ($result->usageid > 0) {
                $quba = question_engine::load_questions_usage_by_activity($result->usageid);
                $slots = $quba->get_slots();

                $slot = 0;
                foreach ($slots as $thisslot) {
                    if ($quba->get_question($thisslot)->id == $questionid) {
                        $slot = $thisslot;
                        break;
                    }
                }
                if ($slot) {
                    question_engine::set_max_mark_in_attempts(new qubaid_list(array($result->usageid)), $slot, $grade);

                    // Now set the new sumgrades also in the offline quiz result.
                    $newquba = question_engine::load_questions_usage_by_activity($result->usageid);
                    $DB->set_field('signinsheet_results', 'sumgrades',  $newquba->get_total_mark(),
                        array('id' => $result->id));
                }
            }
        }
    }
}


/**
 * Update the sumgrades field of the results in an offline quiz.
 *
 * @param object $signinsheet The signinsheet.
 */
function signinsheet_update_all_attempt_sumgrades($signinsheet) {
    global $DB;
    $dm = new question_engine_data_mapper();
    $timenow = time();

    $sql = "UPDATE {signinsheet_results}
               SET timemodified = :timenow,
                   sumgrades = (
                                {$dm->sum_usage_marks_subquery('usageid')}
                               )
             WHERE signinsheetid = :signinsheetid
               AND timefinish <> 0";
    $DB->execute($sql, array('timenow' => $timenow, 'signinsheetid' => $signinsheet->id));
}

/**
 * A {@link qubaid_condition} for finding all the question usages belonging to
 * a particular signinsheet. Used in editlib.php.
 *
 * @copyright  2010 The University of Vienna
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_qubaids_for_signinsheet extends qubaid_join {
    public function __construct($signinsheetid, $offlinegroupid, $includepreviews = true, $onlyfinished = false) {
        $where = 'quiza.signinsheetid = :signinsheetid AND quiza.offlinegroupid = :offlinegroupid';
        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }
        if ($onlyfinished) {
            $where .= ' AND timefinish <> 0';
        }

        parent::__construct('{signinsheet_results} quiza', 'quiza.usageid', $where,
                array('signinsheetid' => $signinsheetid, 'offlinegroupid' => $offlinegroupid));
    }
}

/**
 * The signinsheet grade is the maximum that student's results are marked out of. When it
 * changes, the corresponding data in signinsheet_grades and signinsheet_feedback needs to be
 * rescaled. After calling this function, you probably need to call
 * signinsheet_update_all_attempt_sumgrades, signinsheet_update_all_final_grades and
 * signinsheet_update_grades.
 *
 * @param float $newgrade the new maximum grade for the signinsheet.
 * @param object $signinsheet the signinsheet we are updating. Passed by reference so its
 *      grade field can be updated too.
 * @return bool indicating success or failure.
 */
function signinsheet_set_grade($newgrade, $signinsheet) {
    global $DB;
    // This is potentially expensive, so only do it if necessary.
    if (abs($signinsheet->grade - $newgrade) < 1e-7) {
        // Nothing to do.
        return true;
    }

    // Use a transaction, so that on those databases that support it, this is safer.
    $transaction = $DB->start_delegated_transaction();

    // Update the signinsheet table.
    $DB->set_field('signinsheet', 'grade', $newgrade, array('id' => $signinsheet->id));

    $signinsheet->grade = $newgrade;

    // Update grade item and send all grades to gradebook.
    signinsheet_grade_item_update($signinsheet);
    signinsheet_update_grades($signinsheet);

    $transaction->allow_commit();
    return true;
}


/**
 * Returns info about the JS module used by signinsheetzes.
 *
 * @return multitype:string multitype:string  multitype:multitype:string
 */
function signinsheet_get_js_module() {
    global $PAGE;
    return array(
            'name' => 'mod_signinsheet',
            'fullpath' => '/mod/signinsheet/module.js',
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
 * Returns true if the student has access to results. Function doesn't check if there is a result.
 *
 * @param object signinsheet  The signinsheet object
 */
function signinsheet_results_open($signinsheet) {

    if ($signinsheet->timeclose and time() >= $signinsheet->timeclose) {
        return false;
    }
    if ($signinsheet->timeopen and time() <= $signinsheet->timeopen) {
        return false;
    }

    $options = mod_signinsheet_display_options::make_from_signinsheet($signinsheet);
    // There has to be responses or (graded)sheetfeedback.

    if ($options->attempt == question_display_options::HIDDEN and
            $options->marks == question_display_options::HIDDEN and
            $options->sheetfeedback == question_display_options::HIDDEN and
            $options->gradedsheetfeedback == question_display_options::HIDDEN) {
        return false;
    } else {
        return true;
    }
}

/**
 * @deprecated User identification is now set in admin settings.
 */
function signinsheet_load_useridentification() {
    return;
}

/**
 * Returns the number of pages in a signinsheet layout
 *
 * @param string $layout The string representing the signinsheet layout. Always ends in ,0
 * @return int The number of pages in the signinsheet.
 */
function signinsheet_number_of_pages($layout) {
    return substr_count(',' . $layout, ',0');
}

/**
 * Extends first object with member data of the second
 *
 * @param unknown_type $first
 * @param unknown_type $second
 */
function signinsheet_extend_object (&$first, &$second) {

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
function signinsheet_get_group($signinsheet, $groupnumber) {
    global $DB;

    if (!$signinsheetgroup = $DB->get_record('signinsheet_groups',
                                              array('signinsheetid' => $signinsheet->id, 'number' => $groupnumber))) {
        if ($groupnumber > 0 && $groupnumber <= $signinsheet->numgroups) {
            $signinsheetgroup = signinsheet_add_group( $signinsheet->id, $groupnumber);
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
function signinsheet_add_group($signinsheetid, $groupnumber) {
    GLOBAL $DB;

    $signinsheetgroup = new StdClass();
    $signinsheetgroup->signinsheetid = $signinsheetid;
    $signinsheetgroup->number = $groupnumber;

    // Note: numberofpages and templateusageid will be filled later.

    // Try to store it in the database.
    if (!$signinsheetgroup->id = $DB->insert_record('signinsheet_groups', $signinsheetgroup)) {
        return false;
    }

    return $signinsheetgroup;
}

/**
 * Checks whether any list of participants have been created for a given signinsheet.
 *
 * @param unknown_type $signinsheet
 * @return boolean
 */
function signinsheet_partlist_created($signinsheet) {
    global $DB;

    return $DB->count_records('attendance_ss_p_lists', array('signinsheetid' => $signinsheet->id)) > 0;
}


/**
 * An extension of question_display_options that includes the extra options used
 * by the signinsheet.
 *
 * @copyright     2015 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_signinsheet_display_options extends question_display_options {
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
     * @return mod_signinsheet_display_options set up appropriately.
     */
    public static function make_from_signinsheet($signinsheet) {
        $options = new self();

        $options->attempt = self::extract($signinsheet->review, signinsheet_REVIEW_ATTEMPT);
        $options->marks = self::extract($signinsheet->review, signinsheet_REVIEW_MARKS) ? question_display_options::MARK_AND_MAX : question_display_options::HIDDEN;
        $options->correctness = self::extract($signinsheet->review, signinsheet_REVIEW_CORRECTNESS);
        $options->feedback = self::extract($signinsheet->review, signinsheet_REVIEW_SPECIFICFEEDBACK);
        $options->generalfeedback = self::extract($signinsheet->review, signinsheet_REVIEW_GENERALFEEDBACK);
        $options->rightanswer = self::extract($signinsheet->review, signinsheet_REVIEW_RIGHTANSWER);
        $options->sheetfeedback = self::extract($signinsheet->review, signinsheet_REVIEW_SHEET);
        $options->gradedsheetfeedback = self::extract($signinsheet->review, signinsheet_REVIEW_GRADEDSHEET);

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
 * The appropriate mod_signinsheet_display_options object for this result at this
 * signinsheet right now.
 *
 * @param object $signinsheet the signinsheet instance.
 * @param object $result the result in question.
 * @param $context the signinsheet context.
 *
 * @return mod_signinsheet_display_options
 */
function signinsheet_get_review_options($signinsheet, $result, $context) {

    $options = mod_signinsheet_display_options::make_from_signinsheet($signinsheet);

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
 * list($someoptions, $alloptions) = signinsheet_get_combined_reviewoptions(...)
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
function signinsheet_get_combined_reviewoptions($signinsheet) {
    $fields = array('feedback', 'generalfeedback', 'rightanswer');
    $someoptions = new stdClass();
    $alloptions = new stdClass();
    foreach ($fields as $field) {
        $someoptions->$field = false;
        $alloptions->$field = true;
    }
    $someoptions->marks = question_display_options::HIDDEN;
    $alloptions->marks = question_display_options::MARK_AND_MAX;

    $attemptoptions = mod_signinsheet_display_options::make_from_signinsheet($signinsheet);
    foreach ($fields as $field) {
        $someoptions->$field = $someoptions->$field || $attemptoptions->$field;
        $alloptions->$field = $alloptions->$field && $attemptoptions->$field;
    }
    $someoptions->marks = max($someoptions->marks, $attemptoptions->marks);
    $alloptions->marks = min($alloptions->marks, $attemptoptions->marks);

    return array($someoptions, $alloptions);
}

/**
 * Creates HTML code for a question edit button, used by editlib.php
 *
 * @param int $cmid the course_module.id for this signinsheet.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param string $contentbeforeicon some HTML content to be added inside the link, before the icon.
 * @return the HTML for an edit icon, view icon, or nothing for a question
 *      (depending on permissions).
 */
function signinsheet_question_edit_button($cmid, $question, $returnurl, $contentaftericon = '') {
    global $CFG, $OUTPUT;

    // Minor efficiency saving. Only get strings once, even if there are a lot of icons on one page.
    static $stredit = null;
    static $strview = null;
    if ($stredit === null) {
        $stredit = get_string('edit');
        $strview = get_string('view');
    }

    // What sort of icon should we show?
    $action = '';
    if (!empty($question->id) &&
            (question_has_capability_on($question, 'edit', $question->category) ||
                    question_has_capability_on($question, 'move', $question->category))
    ) {
        $action = $stredit;
        $icon = '/t/edit';
    } else if (!empty($question->id) &&
            question_has_capability_on($question, 'view', $question->category)) {
        $action = $strview;
        $icon = '/i/info';
    }

    // Build the icon.
    if ($action) {
        if ($returnurl instanceof moodle_url) {
            $returnurl = str_replace($CFG->wwwroot, '', $returnurl->out(false));
        }
        $questionparams = array('returnurl' => $returnurl, 'cmid' => $cmid, 'id' => $question->id);
        $questionurl = new moodle_url("$CFG->wwwroot/question/question.php", $questionparams);
        return '<a title="' . $action . '" href="' . $questionurl->out() . '"><img src="' .
                $OUTPUT->pix_url($icon) . '" alt="' . $action . '" />' . $contentaftericon .
                '</a>';
    } else {
        return $contentaftericon;
    }
}

/**
 * Creates HTML code for a question preview button.
 *
 * @param object $signinsheet the signinsheet settings
 * @param object $question the question
 * @param bool $label if true, show the preview question label after the icon
 * @return the HTML for a preview question icon.
 */
function signinsheet_question_preview_button($signinsheet, $question, $label = false) {
    global $CFG, $OUTPUT;
    if (property_exists($question, 'category') &&
            !question_has_capability_on($question, 'use', $question->category)) {
        return '';
    }

    $url = signinsheet_question_preview_url($signinsheet, $question);

    // Do we want a label?
    $strpreviewlabel = '';
    if ($label) {
        $strpreviewlabel = get_string('preview', 'attendance');
    }

    // Build the icon.
    $strpreviewquestion = get_string('previewquestion', 'attendance');
    $image = $OUTPUT->pix_icon('t/preview', $strpreviewquestion);

    $action = new popup_action('click', $url, 'questionpreview',
            question_preview_popup_params());

    return $OUTPUT->action_link($url, $image, $action, array('title' => $strpreviewquestion));
}

/**
 * @param object $signinsheet the signinsheet settings
 * @param object $question the question
 * @return moodle_url to preview this question with the options from this signinsheet.
 */
function signinsheet_question_preview_url($signinsheet, $question) {
    // Get the appropriate display options.
    $displayoptions = mod_signinsheet_display_options::make_from_signinsheet($signinsheet);

    $maxmark = null;
    if (isset($question->maxmark)) {
        $maxmark = $question->maxmark;
    }

    // Work out the correct preview URL.
    return question_preview_url($question->id, null,
            $maxmark, $displayoptions);
}


/**
 * Retrieves a template question usage for an offline group. Creates a new template if there is none.
 * While creating question usage it shuffles the group questions if shuffleanswers is created.
 *
 * @param object $signinsheet
 * @param object $group
 * @param object $context
 * @return question_usage_by_activity
 */
function signinsheet_get_group_template_usage($signinsheet, $group, $context) {
    global $CFG, $DB;

    if (!empty($group->templateusageid) && $group->templateusageid > 0) {
        $templateusage = question_engine::load_questions_usage_by_activity($group->templateusageid);
    } else {

        $questionids = signinsheet_get_group_question_ids($signinsheet, $group->id);

        if ($signinsheet->shufflequestions) {
            $signinsheet->groupid = $group->id;

            $questionids = signinsheet_shuffle_questions($questionids);
        }

        // We have to use our own class s.t. we can use the clone function to create results.
        $templateusage = signinsheet_make_questions_usage_by_activity('mod_signinsheet', $context);
        $templateusage->set_preferred_behaviour('immediatefeedback');

        if (!$questionids) {
            print_error(get_string('noquestionsfound', 'attendance'), 'view.php?q='.$signinsheet->id);
        }

        // Gets database raw data for the questions.
        $questiondata = question_load_questions($questionids);

        // Get the question instances for initial markmarks.
        $sql = "SELECT questionid, maxmark
                  FROM {signinsheet_group_questions}
                 WHERE signinsheetid = :signinsheetid
                   AND offlinegroupid = :offlinegroupid ";

        $groupquestions = $DB->get_records_sql($sql,
                array('signinsheetid' => $signinsheet->id, 'offlinegroupid' => $group->id));

        foreach ($questionids as $questionid) {
            if ($questionid) {
                // Convert the raw data of multichoice questions to a real question definition object.
                if (!$signinsheet->shuffleanswers) {
                    $questiondata[$questionid]->options->shuffleanswers = false;
                }
                $question = question_bank::make_question($questiondata[$questionid]);

                // We only add multichoice questions which are needed for grading.
                if ($question->get_type_name() == 'multichoice' || $question->get_type_name() == 'multichoiceset') {
                    $templateusage->add_question($question, $groupquestions[$question->id]->maxmark);
                }
            }
        }

        // Create attempts for all questions (fixes order of the answers if shuffleanswers is active).
        $templateusage->start_all_questions();

        // Save the template question usage to the DB.
        question_engine::save_questions_usage_by_activity($templateusage);

        // Save the templateusage-ID in the signinsheet_groups table.
        $group->templateusageid = $templateusage->get_id();
        $DB->set_field('signinsheet_groups', 'templateusageid', $group->templateusageid, array('id' => $group->id));
    } // End else.
    return $templateusage;
}


/**
 * Deletes the PDF forms of an signinsheet.
 *
 * @param object $signinsheet
 */
function signinsheet_delete_pdf_forms($signinsheet) {
    global $DB;

    $fs = get_file_storage();

    // If the signinsheet has just been created then there is no cmid.
    if (isset($signinsheet->cmid)) {
        $context = context_module::instance($signinsheet->cmid);

        // Delete PDF documents.
        $files = $fs->get_area_files($context->id, 'mod_signinsheet', 'pdfs');
        foreach ($files as $file) {
            $file->delete();
        }
    }
    // Delete the file names in the signinsheet groups.
    $DB->set_field('signinsheet_groups', 'questionfilename', null, array('signinsheetid' => $signinsheet->id));
    $DB->set_field('signinsheet_groups', 'answerfilename', null, array('signinsheetid' => $signinsheet->id));
    $DB->set_field('signinsheet_groups', 'correctionfilename', null, array('signinsheetid' => $signinsheet->id));

    // Set signinsheet->docscreated to 0.
    $signinsheet->docscreated = 0;
    $DB->set_field('signinsheet', 'docscreated', 0, array('id' => $signinsheet->id));
    return $signinsheet;
}

/**
 * Deletes the question usages by activity for an signinsheet. This function must not be
 * called if the offline quiz has attempts or scanned pages
 *
 * @param object $signinsheet
 */
function signinsheet_delete_template_usages($signinsheet, $deletefiles = true) {
    global $CFG, $DB, $OUTPUT;

    if ($groups = $DB->get_records('signinsheet_groups',
                                   array('signinsheetid' => $signinsheet->id), 'number', '*', 0, $signinsheet->numgroups)) {
        foreach ($groups as $group) {
            if ($group->templateusageid) {
                question_engine::delete_questions_usage_by_activity($group->templateusageid);
                $group->templateusageid = 0;
                $DB->set_field('signinsheet_groups', 'templateusageid', 0, array('id' => $group->id));
            }
        }
    }

    // Also delete the PDF forms if they have been created.
    if ($deletefiles) {
        return signinsheet_delete_pdf_forms($signinsheet);
    } else {
        return $signinsheet;
    }
}

/**
 * Prints a preview for a question in an signinsheet to Stdout.
 *
 * @param object $question
 * @param array $choiceorder
 * @param int $number
 * @param object $context
 */
function signinsheet_print_question_preview($question, $choiceorder, $number, $context, $page) {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/filter/mathjaxloader/filter.php' );

    $letterstr = 'abcdefghijklmnopqrstuvwxyz';

    echo '<div id="q' . $question->id . '" class="preview">
            <div class="question">
              <span class="number">';

    if ($question->qtype != 'description') {
        echo $number.')&nbsp;&nbsp;';
    }
    echo '    </span>';

    $text = question_rewrite_question_preview_urls($question->questiontext, $question->id,
            $question->contextid, 'question', 'questiontext', $question->id,
            $context->id, 'attendance');

    // Remove leading paragraph tags because the cause a line break after the question number.
    $text = preg_replace('!^<p>!i', '', $text);

    // Filter only for tex formulas.
    $texfilter = null;
    $mathjaxfilter = null;
    $filters = filter_get_active_in_context($context);

    if (array_key_exists('mathjaxloader', $filters)) {
        $mathjaxfilter = new filter_mathjaxloader($context, array());
        $mathjaxfilter->setup($page, $context);
    }
    if (array_key_exists('tex', $filters)) {
        $texfilter = new filter_tex($context, array());
    }
    if ($mathjaxfilter) {
        $text = $mathjaxfilter->filter($text);
        if ($question->qtype != 'description') {
            foreach ($choiceorder as $key => $answer) {
                $question->options->answers[$answer]->answer = $mathjaxfilter->filter($question->options->answers[$answer]->answer);
            }
        }
    } else if ($texfilter) {
        $text = $texfilter->filter($text);
        if ($question->qtype != 'description') {
            foreach ($choiceorder as $key => $answer) {
                $question->options->answers[$answer]->answer = $texfilter->filter($question->options->answers[$answer]->answer);
            }
        }
    }

    echo $text;

    echo '  </div>';
    if ($question->qtype != 'description') {
        echo '  <div class="grade">';
        echo '(' . get_string('marks', 'quiz') . ': ' . ($question->maxmark + 0) . ')';
        echo '  </div>';

        foreach ($choiceorder as $key => $answer) {
            $answertext = $question->options->answers[$answer]->answer;

            // Remove all HTML comments (typically from MS Office).
            $answertext = preg_replace("/<!--.*?--\s*>/ms", "", $answertext);
            // Remove all paragraph tags because they mess up the layout.
            $answertext = preg_replace("/<p[^>]*>/ms", "", $answertext);
            $answertext = preg_replace("/<\/p[^>]*>/ms", "", $answertext);
            // rewrite image URLs
            $answertext = question_rewrite_question_preview_urls($answertext, $question->id,
            $question->contextid, 'question', 'answer', $question->options->answers[$answer]->id,
            $context->id, 'signinsheet');

            echo "<div class=\"answer\">$letterstr[$key])&nbsp;&nbsp;";
            echo $answertext;
            echo "</div>";
        }
    }
    echo "</div>";
}

/**
 * Prints a list of participants to Stdout.
 *
 * @param unknown_type $signinsheet
 * @param unknown_type $coursecontext
 * @param unknown_type $systemcontext
 */
function signinsheet_print_partlist($signinsheet, &$coursecontext, &$systemcontext) {
    global $CFG, $COURSE, $DB, $OUTPUT;
    signinsheet_load_useridentification();
    $signinsheetconfig = get_config('attendance');

    if (!$course = $DB->get_record('course', array('id' => $coursecontext->instanceid))) {
        print_error('invalid course');
    }
    $pagesize = optional_param('pagesize', NUMBERS_PER_PAGE, PARAM_INT);
    $checkoption = optional_param('checkoption', 0, PARAM_INT);
    $listid = optional_param('listid', '', PARAM_INT);
    $lists = $DB->get_records_sql("
            SELECT id, number, name
              FROM {attendance_ss_p_lists}
             WHERE signinsheetid = :signinsheetid
          ORDER BY number ASC",
            array('signinsheetid' => $signinsheet->id));

    // First get roleids for students from leagcy.
    if (!$roles = get_roles_with_capability('mod/signinsheet:attempt', CAP_ALLOW, $systemcontext)) {
        print_error("No roles with capability 'mod/signinsheet:attempt' defined in system context");
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
              FROM {signinsheet_participants} p,
                   {attendance_ss_p_lists} pl,
                   {user} u,
                   {role_assignments} ra
             WHERE p.listid = pl.id
               AND p.userid = u.id
               AND ra.userid=u.id
               AND pl.signinsheetid = :signinsheetid
               AND ra.contextid $csql
               AND ra.roleid $rsql";

    $params['signinsheetid'] = $signinsheet->id;
    if (!empty($listid)) {
        $sql .= " AND p.listid = :listid";
        $params['listid'] = $listid;
    }

    $countsql = "SELECT COUNT(*)
                   FROM {signinsheet_participants} p,
                        {attendance_ss_p_lists} pl,
                        {user} u
                  WHERE p.listid = pl.id
                    AND p.userid = u.id
                    AND pl.signinsheetid = :signinsheetid";

    $cparams = array('signinsheetid' => $signinsheet->id);
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

    $table = new signinsheet_partlist_table('mod-signinsheet-participants', 'participants.php', $tableparams);

    // Define table columns.
    $tablecolumns = array('checkbox', 'picture', 'fullname', $signinsheetconfig->ID_field, 'number', 'attempt', 'checked');
    $tableheaders = array('<input type="checkbox" name="toggle" class="select-all-checkbox"/>',
            '', get_string('fullname'), get_string($signinsheetconfig->ID_field), get_string('participantslist', 'attendance'),
            get_string('attemptexists', 'attendance'), get_string('present', 'attendance'));

    $table->define_columns($tablecolumns);
    $table->define_headers($tableheaders);
    $table->define_baseurl($CFG->wwwroot.'/mod/signinsheet/participants.php?mode=attendances&amp;q=' .
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
              FROM {signinsheet_results}
             WHERE userid = :userid
               AND signinsheetid = :signinsheetid
               AND status = 'complete'";
    $params = array('signinsheetid' => $signinsheet->id);
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
                    $attempt ? "<img src=\"$CFG->wwwroot/mod/signinsheet/pix/tick.gif\" alt=\"" .
                    get_string('attemptexists', 'attendance') . "\">" : "<img src=\"$CFG->wwwroot/mod/signinsheet/pix/cross.gif\" alt=\"" .
                    get_string('noattemptexists', 'attendance') . "\">",
                    $participant->checked ? "<img src=\"$CFG->wwwroot/mod/signinsheet/pix/tick.gif\" alt=\"" .
                    get_string('ischecked', 'attendance') . "\">" : "<img src=\"$CFG->wwwroot/mod/signinsheet/pix/cross.gif\" alt=\"" .
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
        echo '<form id="downloadoptions" action="participants.php" method="get">';
        echo '<input type="hidden" name="q" value="' . $signinsheet->id . '" />';
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
    echo '<form id="options" action="participants.php" method="get">';
    echo '<center>';
    echo '<p>'.get_string('displayoptions', 'quiz').': </p>';
    echo '<input type="hidden" name="q" value="' . $signinsheet->id . '" />';
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
 * @param unknown_type $signinsheet
 * @param unknown_type $fileformat
 * @param unknown_type $coursecontext
 * @param unknown_type $systemcontext
 */
function signinsheet_download_partlist($signinsheet, $fileformat, &$coursecontext, &$systemcontext) {
    global $CFG, $DB, $COURSE;

    signinsheet_load_useridentification();
    $signinsheetconfig = get_config('attendance');

    $filename = clean_filename(get_string('participants', 'attendance') . $signinsheet->id);

    // First get roleids for students from leagcy.
    if (!$roles = get_roles_with_capability('mod/signinsheet:attempt', CAP_ALLOW, $systemcontext)) {
        print_error("No roles with capability 'mod/signinsheet:attempt' defined in system context");
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
             FROM {signinsheet_participants} p,
                  {attendance_ss_p_lists} pl,
                  {user} u,
                  {role_assignments} ra
            WHERE p.listid = pl.id
              AND p.userid = u.id
              AND ra.userid=u.id
              AND pl.signinsheetid = :signinsheetid
              AND ra.contextid $csql
              AND ra.roleid $rsql";

    $params['signinsheetid'] = $signinsheet->id;

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

    $lists = $DB->get_records('attendance_ss_p_lists', array('signinsheetid' => $signinsheet->id));
    $participants = $DB->get_records_sql($sql, $params);
    if ($participants) {
        foreach ($participants as $participant) {
            $userid = $participant->userid;
            $attempt = false;
            $sql = "SELECT COUNT(*)
                      FROM {signinsheet_results}
                     WHERE userid = :userid
                       AND signinsheetid = :signinsheetid
                       AND status = 'complete'";
            if ($DB->count_records_sql($sql, array('userid' => $userid, 'signinsheetid' => $signinsheet->id)) > 0) {
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

/**
 * Creates a textual representation of a question for display.
 *
 * @param object $question A question object from the database questions table
 * @param bool $showicon If true, show the question's icon with the question. False by default.
 * @param bool $showquestiontext If true (default), show question text after question name.
 *       If false, show only question name.
 * @param bool $return If true (default), return the output. If false, print it.
 */
function signinsheet_question_tostring($question, $showicon = false,
        $showquestiontext = true, $return = true, $shorttitle = false) {
    global $COURSE;

    $result = '';

    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $formatoptions->para = false;

    $questiontext = strip_tags(question_utils::to_plain_text($question->questiontext, $question->questiontextformat,
                                                             array('noclean' => true, 'para' => false)));
    $questiontitle = strip_tags(format_text($question->name, $question->questiontextformat, $formatoptions, $COURSE->id));

    $result .= '<span class="questionname" title="' . $questiontitle . '">';
    if ($shorttitle && strlen($questiontitle) > 25) {
        $questiontitle = shorten_text($questiontitle, 25, false, '...');
    }

    if ($showicon) {
        $result .= print_question_icon($question, true);
        echo ' ';
    }

    if ($shorttitle) {
        $result .= $questiontitle;
    } else {
        $result .= shorten_text(format_string($question->name), 200) . '</span>';
    }

    if ($showquestiontext) {
        $result .= '<span class="questiontext" title="' . $questiontext . '">';

        $questiontext = shorten_text($questiontext, 200);

        if (!empty($questiontext)) {
            $result .= $questiontext;
        } else {
            $result .= '<span class="error">';
            $result .= get_string('questiontextisempty', 'attendance');
            $result .= '</span>';
        }
        $result .= '</span>';
    }
    if ($return) {
        return $result;
    } else {
        echo $result;
    }
}
/**
 * Add a question to a signinsheet group
 *
 * Adds a question to a signinsheet by updating $signinsheet as well as the
 * signinsheet and signinsheet_question_instances tables. It also adds a page break
 * if required.
 * @param int $id The id of the question to be added
 * @param object $signinsheet The extended signinsheet object as used by edit.php
 *      This is updated by this function
 * @param int $page Which page in signinsheet to add the question on. If 0 (default),
 *      add at the end
 * @return bool false if the question was already in the signinsheet
 */
function signinsheet_add_questionlist_to_group($questionids, $signinsheet, $offlinegroup,
        $fromofflinegroup = null, $maxmarks = null) {
    global $DB;

    if (signinsheet_has_scanned_pages($signinsheet->id)) {
        return false;
    }

    // Don't add the same question twice.
    foreach ($questionids as $questionid) {
        $slots = $DB->get_records('signinsheet_group_questions',
                array('signinsheetid' => $signinsheet->id, 'offlinegroupid' => $offlinegroup->id),
                'slot', 'questionid, slot, page, id');

        if (array_key_exists($questionid, $slots)) {
            continue;
        }

        $trans = $DB->start_delegated_transaction();
        // If the question is already in another group, take the maxmark of that.
        $maxmark = null;
        if ($fromofflinegroup && $oldmaxmark = $DB->get_field('signinsheet_group_questions', 'maxmark',
            array('signinsheetid' => $signinsheet->id,
            'offlinegroupid' => $fromofflinegroup,
            'questionid' => $questionid))) {
            $maxmark = $oldmaxmark;
        } else if ($maxmarks && array_key_exists($questionid, $maxmarks)) {
            $maxmark = $maxmarks[$questionid];
        }

        $maxpage = 1;
        $numonlastpage = 0;
        foreach ($slots as $slot) {
            if ($slot->page > $maxpage) {
                $maxpage = $slot->page;
                $numonlastpage = 1;
            } else {
                $numonlastpage += 1;
            }
        }

        // Add the new question instance.
        $slot = new stdClass();
        $slot->signinsheetid = $signinsheet->id;
        $slot->offlinegroupid = $offlinegroup->id;
        $slot->questionid = $questionid;

        if ($maxmark !== null) {
            $slot->maxmark = $maxmark;
        } else {
            $slot->maxmark = $DB->get_field('question', 'defaultmark', array('id' => $questionid));
        }

        $lastslot = end($slots);
        if ($lastslot) {
            $slot->slot = $lastslot->slot + 1;
        } else {
            $slot->slot = 1;
        }
        $slot->page = 0;

        if (!$slot->page) {
            if ($signinsheet->questionsperpage && $numonlastpage >= $signinsheet->questionsperpage) {
                $slot->page = $maxpage + 1;
            } else {
                $slot->page = $maxpage;
            }
        }
        $DB->insert_record('signinsheet_group_questions', $slot);
        $trans->allow_commit();
    }
}

/**
 * Randomly add a number of multichoice questions to an signinsheet group.
 *
 * @param unknown_type $signinsheet
 * @param unknown_type $addonpage
 * @param unknown_type $categoryid
 * @param unknown_type $number
 * @param unknown_type $includesubcategories
 */
function signinsheet_add_random_questions($signinsheet, $offlinegroup, $categoryid,
        $number, $recurse, $preventsamequestion) {
    global $DB;

    $category = $DB->get_record('question_categories', array('id' => $categoryid));
    if (!$category) {
        print_error('invalidcategoryid', 'error');
    }

    $catcontext = context::instance_by_id($category->contextid);
    require_capability('moodle/question:useall', $catcontext);

    if ($recurse) {
        $categoryids = question_categorylist($category->id);
    } else {
        $categoryids = array($category->id);
    }

    list($qcsql, $qcparams) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'qc');

    $sql = "SELECT id
              FROM {question} q
             WHERE q.category $qcsql
               AND q.parent = 0
               AND q.hidden = 0
               AND q.qtype IN ('multichoice', 'multichoiceset') ";
    if (!$preventsamequestion) {
        // Find all questions in the selected categories that are not in the offline group yet.
        $sql .= "AND NOT EXISTS (SELECT 1
                                   FROM {signinsheet_group_questions} ogq
                                  WHERE ogq.questionid = q.id
                                    AND ogq.signinsheetid = :signinsheetid
                                    AND ogq.offlinegroupid = :offlinegroupid)";
    } else {
        // Find all questions in the selected categories that are not in the offline test yet.
        $sql .= "AND NOT EXISTS (SELECT 1
                                   FROM {signinsheet_group_questions} ogq
                                  WHERE ogq.questionid = q.id
                                    AND ogq.signinsheetid = :signinsheetid)";
    }
    $qcparams['signinsheetid'] = $signinsheet->id;
    $qcparams['offlinegroupid'] = $offlinegroup->id;

    $questionids = $DB->get_fieldset_sql($sql, $qcparams);
    shuffle($questionids);

    $chosenids = array();
    while (($questionid = array_shift($questionids)) && $number > 0) {
        $chosenids[] = $questionid;
        $number -= 1;
    }

    $maxmarks = array();
    if ($chosenids) {
        // Get the old maxmarks in case questions are already in other signinsheet groups.
        list($qsql, $params) = $DB->get_in_or_equal($chosenids, SQL_PARAMS_NAMED);

        $sql = "SELECT id, questionid, maxmark
                  FROM {signinsheet_group_questions}
                 WHERE signinsheetid = :signinsheetid
                   AND questionid $qsql";
        $params['signinsheetid'] = $signinsheet->id;

        if ($slots = $DB->get_records_sql($sql, $params)) {
            foreach ($slots as $slot) {
                if (!array_key_exists($slot->questionid, $maxmarks)) {
                    $maxmarks[$slot->questionid] = $slot->maxmark;
                }
            }
        }
    }

    signinsheet_add_questionlist_to_group($chosenids, $signinsheet, $offlinegroup, null, $maxmarks);
}

/**
 *
 * @param unknown $signinsheet
 * @param unknown $questionids
 */
function signinsheet_remove_questionlist($signinsheet, $questionids) {
    global $DB;

    // Go through the question IDs and remove them if they exist.
    // We do a DB commit after each question ID to make things simpler.
    foreach ($questionids as $questionid) {
        // Retrieve the slots indexed by id.
        $slots = $DB->get_records('signinsheet_group_questions',
                array('signinsheetid' => $signinsheet->id, 'offlinegroupid' => $signinsheet->groupid),
                'slot');

        // Build an array with slots indexed by questionid and indexed by slot number.
        $questionslots = array();
        $slotsinorder = array();
        foreach ($slots as $slot) {
            $questionslots[$slot->questionid] = $slot;
            $slotsinorder[$slot->slot] = $slot;
        }

        if (!array_key_exists($questionid, $questionslots)) {
            continue;
        }

        $slot = $questionslots[$questionid];

        $nextslot = null;
        $prevslot = null;
        if (array_key_exists($slot->slot + 1, $slotsinorder)) {
            $nextslot = $slotsinorder[$slot->slot + 1];
        }
        if (array_key_exists($slot->slot - 1, $slotsinorder)) {
            $prevslot = $slotsinorder[$slot->slot - 1];
        }
        $lastslot = end($slotsinorder);

        $trans = $DB->start_delegated_transaction();

        // Reduce the page numbers of the following slots if there is no previous slot
        // or the page number of the previous slot is smaller than the page number of the current slot.
        $removepage = false;
        if ($nextslot && $nextslot->page > $slot->page) {
            if (!$prevslot || $prevslot->page < $slot->page) {
                $removepage = true;
            }
        }

        // Delete the slot.
        $DB->delete_records('signinsheet_group_questions',
                array('signinsheetid' => $signinsheet->id, 'offlinegroupid' => $signinsheet->groupid,
                      'id' => $slot->id));

        // Reduce the slot number in the following slots if there are any.
        // Also reduce the page number if necessary.
        if ($nextslot) {
            for ($curslotnr = $nextslot->slot; $curslotnr <= $lastslot->slot; $curslotnr++) {
                if ($slotsinorder[$curslotnr]) {
                    if ($removepage) {
                        $slotsinorder[$curslotnr]->page = $slotsinorder[$curslotnr]->page - 1;
                    }
                    // Reduce the slot number by one.
                    $slotsinorder[$curslotnr]->slot = $slotsinorder[$curslotnr]->slot - 1;
                    $DB->update_record('signinsheet_group_questions', $slotsinorder[$curslotnr]);
                }
            }
        }

        $trans->allow_commit();
    }
}
