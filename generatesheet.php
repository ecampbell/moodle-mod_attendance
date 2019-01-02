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
 * Take Attendance
 *
 * @package    mod_attendance
 * @copyright  2011 Artem Andreev <andreev.artem@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__).'/../../config.php');
require_once(dirname(__FILE__).'/locallib.php');

require_once($CFG->dirroot . '/mod/offlinequiz/locallib.php');
require_once($CFG->dirroot . '/mod/offlinequiz/pdflib.php');
require_once($CFG->dirroot . '/mod/offlinequiz/evallib.php');
require_once($CFG->dirroot . '/mod/offlinequiz/participants/participants_listform.php');
require_once($CFG->dirroot . '/mod/offlinequiz/participants/participants_uploadform.php');
require_once($CFG->dirroot . '/mod/offlinequiz/participants/participants_report.php');
require_once($CFG->dirroot . '/mod/offlinequiz/participants/participants_scanner.php');

$pageparams = new mod_attendance_take_page_params();

$id                     = required_param('id', PARAM_INT);
$pageparams->sessionid  = required_param('sessionid', PARAM_INT);
$pageparams->grouptype  = required_param('grouptype', PARAM_INT);
$pageparams->sort       = optional_param('sort', ATT_SORT_DEFAULT, PARAM_INT);
$pageparams->copyfrom   = optional_param('copyfrom', null, PARAM_INT);
$pageparams->viewmode   = optional_param('viewmode', null, PARAM_INT);
$pageparams->gridcols   = optional_param('gridcols', null, PARAM_INT);
$pageparams->page       = optional_param('page', 1, PARAM_INT);
$pageparams->perpage    = optional_param('perpage', get_config('attendance', 'resultsperpage'), PARAM_INT);

define("MAX_USERS_PER_PAGE", 5000);

$q = optional_param('q', 0, PARAM_INT);                 // Or session ID.
$forcenew = optional_param('forcenew', 0, PARAM_INT);
$mode = optional_param('mode', 'editparticipants', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$download = optional_param('download', false, PARAM_ALPHA);

$cm             = get_coursemodule_from_id('attendance', $id, 0, false, MUST_EXIST);
$course         = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$att            = $DB->get_record('attendance', array('id' => $cm->instance), '*', MUST_EXIST);
// Check this is a valid session for this attendance.
$session        = $DB->get_record('attendance_sessions', array('id' => $pageparams->sessionid, 'attendanceid' => $att->id),
                                  '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
$coursecontext = context_course::instance($cm->course);
$systemcontext = context_system::instance();

$pageparams->group = groups_get_activity_group($cm, true);

$pageparams->init($course->id);
$att = new mod_attendance_structure($att, $cm, $course, $PAGE->context, $pageparams);

$allowedgroups = groups_get_activity_allowed_groups($cm);
if (!empty($pageparams->grouptype) && !array_key_exists($pageparams->grouptype, $allowedgroups)) {
     $group = groups_get_group($pageparams->grouptype);
     throw new moodle_exception('cannottakeforgroup', 'attendance', '', $group->name);
}

if (($formdata = data_submitted()) && confirm_sesskey()) {
    $att->take_from_form_data($formdata);

    $group = 0;
    if ($att->pageparams->grouptype != mod_attendance_structure::SESSION_COMMON) {
        $group = $att->pageparams->grouptype;
    } else {
        if ($att->pageparams->group) {
            $group = $att->pageparams->group;
        }
    }

    $totalusers = count_enrolled_users(context_module::instance($cm->id), 'mod/attendance:canbelisted', $group);
    $usersperpage = $att->pageparams->perpage;

    if (!empty($att->pageparams->page) && $att->pageparams->page && $totalusers && $usersperpage) {
        $numberofpages = ceil($totalusers / $usersperpage);
        if ($att->pageparams->page < $numberofpages) {
            $params = array(
                'sessionid' => $att->pageparams->sessionid,
                'grouptype' => $att->pageparams->grouptype);
            $params['page'] = $att->pageparams->page + 1;
            redirect($att->url_generate($params), get_string('moreattendance', 'attendance'));
        }
    }

    redirect($att->url_manage(), get_string('attendancesuccess', 'attendance'));
}

$thispageurl = $att->url_generate();
$PAGE->set_url($thispageurl);
$PAGE->set_title($course->shortname. ": ".$att->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_cacheable(true);
$PAGE->navbar->add($att->name);

$output = $PAGE->get_renderer('mod_attendance');
$tabs = new attendance_tabs($att);
$sesstable = new attendance_take_data($att);

// Output starts here.

$PAGE->set_pagelayout('admin');
$node = $PAGE->settingsnav->find('mod_attendance_participants', navigation_node::TYPE_SETTING);
$PAGE->force_settings_menu(true);
if ($node) {
    $node->make_active();
}

$PAGE->requires->yui_module('moodle-mod_attendance-toolboxes',
        'M.mod_attendance.init_resource_toolbox',
        array(array(
                'courseid' => $course->id,
                'sessionid' => $pageparams->sessionid
        ))
        );

function find_pdf_file($contextid, $listfilename) {
    $fs = get_file_storage();
    if ($pdffile = $fs->get_file($contextid, 'mod_attendance', 'participants', 0, '/', $listfilename)) {
        return $pdffile;
    } else {
        return $fs->get_file($contextid, 'mod_attendance', 'pdfs', 0, '/', $listfilename);
    }
}

// Only print headers and tabs if not asked to download data.
if (!$download) {
    echo $output->header();
    echo $output->heading(get_string('signinsheetforthecourse', 'attendance') . ' :: ' . format_string($course->fullname));
    echo $output->render($tabs);
    echo $output->render($sesstable);
    echo $OUTPUT->heading_with_help(get_string('signinsheetcreatepdfsparticipants', 'attendance'), 'participants', 'attendance');
}

echo $OUTPUT->box_start('boxaligncenter generalbox boxwidthnormal');
$pdffile = signinsheet_create_pdf_participants($att, $course->id, $context);

foreach ($lists as $list) {
    $fs = get_file_storage();

    $pdffile = null;
    // Create PDF file if necessary.
    if (!property_exists($list, 'filename') ||  !$list->filename ||
            !$pdffile = find_pdf_file($context->id, $list->filename)) {
        $pdffile = signinsheet_create_pdf_participants($offlinequiz, $course->id, $list, $context);
        if (!empty($pdffile)) {
            $list->filename = $pdffile->get_filename();
        }
        $DB->update_record('offlinequiz_p_lists', $list);
    }

    // Show downloadlink.
    if ($pdffile) {
        $url = "$CFG->wwwroot/pluginfile.php/" . $pdffile->get_contextid() . '/' . $pdffile->get_component() . '/' .
            $pdffile->get_filearea() . '/' . $pdffile->get_itemid() . '/' . $pdffile->get_filename() .
            '?forcedownload=1';
        echo $OUTPUT->action_link($url, trim(format_text(get_string('downloadpartpdf', 'offlinequiz', $list->name))));

        $list->filename = $pdffile->get_filename();
        $DB->update_record('offlinequiz_p_lists', $list);
    } else {
        echo $OUTPUT->notification(format_text(get_string('createpartpdferror', 'offlinequiz', $list->name)));
    }
    echo '<br />&nbsp;<br />';
}
echo $OUTPUT->box_end();

// Finish the page.
if (!$download) {
    echo $OUTPUT->footer();
}
