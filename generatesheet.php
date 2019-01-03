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
require_once(dirname(__FILE__).'/signinsheetpdf.php');

require_once($CFG->dirroot . '/mod/offlinequiz/locallib.php');
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
$attendance     = $DB->get_record('attendance', array('id' => $cm->instance), '*', MUST_EXIST);
// Check this is a valid session for this attendance.
$session        = $DB->get_record('attendance_sessions', array('id' => $pageparams->sessionid, 'attendanceid' => $attendance->id),
                                  '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
$coursecontext = context_course::instance($cm->course);
$systemcontext = context_system::instance();

$pageparams->group = groups_get_activity_group($cm, true);

$pageparams->init($course->id);
$att = new mod_attendance_structure($attendance, $cm, $course, $PAGE->context, $pageparams);

$allowedgroups = groups_get_activity_allowed_groups($cm);
if (!empty($pageparams->grouptype) && !array_key_exists($pageparams->grouptype, $allowedgroups)) {
     $group = groups_get_group($pageparams->grouptype);
     throw new moodle_exception('cannottakeforgroup', 'attendance', '', $group->name);
}

$thispageurl = $att->url_generate();
$PAGE->set_url($thispageurl);
$PAGE->set_title($course->shortname. ": ".$att->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_cacheable(true);
$PAGE->navbar->add($att->name);

$output = $PAGE->get_renderer('mod_attendance');
// $tabs = new attendance_tabs($att);
$participants = new attendance_take_data($att);

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

// Only print headers and tabs if not asked to download data.
if (!$download) {
    echo $output->header();
    echo $output->heading(get_string('signinsheetforthecourse', 'attendance') . ' :: ' . format_string($course->fullname));
    // echo $output->render($tabs);
    // echo $output->render($sesstable);
    echo $OUTPUT->heading_with_help(get_string('signinsheetcreatepdfsparticipants', 'attendance'), 'participants', 'attendance');
}

echo $OUTPUT->box_start('boxaligncenter generalbox boxwidthnormal');

// Generate the list of participants as a PDF file
$pdffile = signinsheet_create_pdf_participants($att, $course->id, $participants, null, $context);
if ($pdffile) {
    $url = "$CFG->wwwroot/pluginfile.php/" . $pdffile->get_contextid() . '/' . $pdffile->get_component() . '/' .
        $pdffile->get_filearea() . '/' . $pdffile->get_itemid() . '/' . $pdffile->get_filename() .
        '?forcedownload=1';
    echo $OUTPUT->action_link($url, get_string('signinsheetpdfdownload', 'attendance', $pdffile->get_filename()));
} else {
    echo $OUTPUT->notification(get_string('signinsheetpdferror', 'attendance', $list->name));
}
echo '<br />&nbsp;<br />';
echo $OUTPUT->box_end();

// Finish the page.
if (!$download) {
    echo $OUTPUT->footer();
}
