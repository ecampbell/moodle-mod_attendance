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
 * Manage Attendance signin sheets
 *
 * @package    mod_attendance
 * @copyright  2018 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__).'/../../config.php');
require_once(dirname(__FILE__).'/locallib.php');
require_once(dirname(__FILE__).'/signinsheetspdflib.php');
require_once(dirname(__FILE__) . '/signinsheetsevallib.php');

$pageparams = new mod_attendance_take_page_params();

$id                     = required_param('id', PARAM_INT);
// $pageparams->sessionid  = required_param('sessionid', PARAM_INT);
$pageparams->sessionid  = optional_param('sessionid', null, PARAM_INT);
$pageparams->grouptype  = optional_param('grouptype', 0, PARAM_INT);
$pageparams->sort       = optional_param('sort', ATT_SORT_DEFAULT, PARAM_INT);
$pageparams->copyfrom   = optional_param('copyfrom', null, PARAM_INT);
$pageparams->viewmode   = optional_param('viewmode', null, PARAM_INT);
$pageparams->gridcols   = optional_param('gridcols', null, PARAM_INT);
$pageparams->page       = optional_param('page', 1, PARAM_INT);
$pageparams->perpage    = optional_param('perpage', get_config('attendance', 'resultsperpage'), PARAM_INT);

define("MAX_USERS_PER_PAGE", 5000);

$q = optional_param('q', 0, PARAM_INT);                 // Or session ID.
$forcenew = optional_param('forcenew', 0, PARAM_INT);
$action = optional_param('action', 0, PARAM_INT);
$download = optional_param('download', false, PARAM_ALPHA);

$cm             = get_coursemodule_from_id('attendance', $id, 0, false, MUST_EXIST);
$course         = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$attendance     = $DB->get_record('attendance', array('id' => $cm->instance), '*', MUST_EXIST);
if (!$download && $pageparams->sessionid != null) {
    // Check this is a valid session for this attendance if we are downloading a PDF file.
    $session = $DB->get_record('attendance_sessions', array('id' => $pageparams->sessionid, 'attendanceid' => $attendance->id),
                                  '*', MUST_EXIST);
}

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

$thispageurl = $att->url_signinsheets();
$PAGE->set_url($thispageurl);
$PAGE->set_title($course->shortname. ": ".$att->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_cacheable(true);
$PAGE->navbar->add($att->name);

$output = $PAGE->get_renderer('mod_attendance');

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

// Only print headers and tabs if not asked to download a signin sheet for a session.
if (!$download) {
    echo $output->header();
    echo $output->heading(get_string('signinsheetforthecourse', 'attendance') . ' :: ' . format_string($course->fullname));
}

function find_pdf_file($contextid, $listfilename) {
    $fs = get_file_storage();
    if ($pdffile = $fs->get_file($contextid, 'mod_attendance', 'participants', 0, '/', $listfilename)) {
        return $pdffile;
    } else {
        return $fs->get_file($contextid, 'mod_attendance', 'pdfs', 0, '/', $listfilename);
    }
}

switch($action) {
    case mod_attendance_sessions_page_params::ACTION_DOWNLOAD:
        // Get the attendance list for the session, and generate it as a PDF file.
        echo $OUTPUT->box_start('boxaligncenter generalbox boxwidthnormal');
        $participants = new attendance_take_data($att);
        $list = new stdClass();
        $list->sessionid = $pageparams->sessionid;
        $list->attendanceid = $att->id;
        $list->name = date("D d/m/Y", $session->sessdate);
        $list->id = 0; // Need to fix this later.
        $pdffile = signinsheet_create_pdf_participants($att, $course->id, $participants, $list, $context);
        if ($pdffile) {
            $url = "$CFG->wwwroot/pluginfile.php/" . $pdffile->get_contextid() . '/' . $pdffile->get_component() . '/' .
                $pdffile->get_filearea() . '/' . $pdffile->get_itemid() . '/' . $pdffile->get_filename() .
                '?forcedownload=1';
            echo $OUTPUT->action_link($url, trim(format_text(get_string('signinsheetpdfdownload', 'attendance', $pdffile->get_filename()))));
        } else {
            echo $OUTPUT->notification(get_string('signinsheetpdferror', 'attendance', $list->name));
        }
        echo '<br />&nbsp;<br />';
        echo $OUTPUT->box_end();
        break;
    case mod_attendance_sessions_page_params::ACTION_MANAGE:
        // We redirect if no list has been created.
        // if (!signinsheet_partlist_created($signinsheet)) {
        //     redirect('signinsheets.php?att='.$att->id, get_string('signinsheetcreatelistfirst', 'attendance'));
        // }
        echo $OUTPUT->heading_with_help(get_string('signinsheetparticipantsfiles', 'attendance'), 'signinsheetparticipants', 'attendance');
        $tabs = new attendance_tabs($att, attendance_tabs::TAB_MANAGE);
        $title = get_string('signinsheetparticipantsmanage', 'attendance').' :: ' .format_string($course->fullname);
        $header = new mod_attendance_header($att, $title);
        echo $output->header();
        echo $output->render($header);
        $tabs = new attendance_tabs($att, attendance_tabs::TAB_MANAGE);
        echo $output->render($tabs);

        echo $OUTPUT->heading(format_string($signinsheet->name));
        echo $OUTPUT->heading_with_help(get_string('signinsheetparticipantsfiles', 'attendance'), 'signinsheetparticipants', 'attendance');
        // Show update button.
        ?>

        <div class="singlebutton" align="center">
            <form action="<?php echo "$CFG->wwwroot/mod/attendance/signinsheet.php" ?>" method="post">
                <div>
                    <input type="hidden" name="att" value="<?php echo $att->id ?>" />
                    <input type="hidden" name="forcenew" value="1" />
                    <input type="hidden" name="action" value="manage" />
                    <button type="submit"
                    onClick='return confirm("<?php echo get_string('signinsheetreallydeleteupdatepdf', 'attendance') ?>")' 
                    class="btn btn-secondary">
            <?php echo get_string('signinsheetdeleteupdatepdf', 'attendance') ?>
                    </button>
                </div>
            </form>
            <br>&nbsp;<br>
        </div>
        <?php

        echo $OUTPUT->box_start('boxaligncenter generalbox boxwidthnormal');

        $sql = "SELECT id, name, number, filename
                FROM {attendance_ss_p_lists}
                WHERE attendanceid = :attendanceid
                ORDER BY name ASC";

        $lists = $DB->get_records_sql($sql, array('attendanceid' => $att->id));

        foreach ($lists as $list) {
            $fs = get_file_storage();

            // Delete existing pdf if forcenew.
            if ($forcenew && property_exists($list, 'filename') && $list->filename
                    && $file = find_pdf_file($context->id, $list->filename)) {
                $file->delete();
                $list->filename = null;
            }

            $pdffile = null;
            // Create PDF file if necessary.
            if (!property_exists($list, 'filename') ||  !$list->filename ||
                    !$pdffile = find_pdf_file($context->id, $list->filename)) {
                $pdffile = signinsheet_create_pdf_participants($att, $course->id, $list, $context);
                if (!empty($pdffile)) {
                    $list->filename = $pdffile->get_filename();
                }
                $DB->update_record('attendance_ss_p_lists', $list);
            }

            // Show downloadlink.
            if ($pdffile) {
                $url = "$CFG->wwwroot/pluginfile.php/" . $pdffile->get_contextid() . '/' . $pdffile->get_component() . '/' .
                    $pdffile->get_filearea() . '/' . $pdffile->get_itemid() . '/' . $pdffile->get_filename() .
                    '?forcedownload=1';
                echo $OUTPUT->action_link($url, trim(format_text(get_string('signinsheetdownloadpartpdf', 'attendance', $list->name))));

                $list->filename = $pdffile->get_filename();
                $DB->update_record('attendance_ss_p_lists', $list);
            } else {
                echo $OUTPUT->notification(format_text(get_string('signinsheetcreatepartpdferror', 'attendance', $list->name)));
            }
            echo '<br />&nbsp;<br />';
        }

        // Only print headers and tabs if not asked to download data.
        if (!$download) {
            echo $output->header();
            echo $output->heading(get_string('signinsheetforthecourse', 'attendance') . ' :: ' . format_string($course->fullname));
            // echo $output->render($tabs);
            // echo $output->render($sesstable);
            echo $OUTPUT->heading_with_help(get_string('signinsheetcreatepdfsparticipants', 'attendance'), 'signinsheetparticipants', 'attendance');
        }

        echo $OUTPUT->box_start('boxaligncenter generalbox boxwidthnormal');

        // Generate the list of participants as a PDF file
        $pdffile = signinsheet_create_pdf_participants($att, $course->id, $participants, null, $context);
        if ($pdffile) {
            $url = "$CFG->wwwroot/pluginfile.php/" . $pdffile->get_contextid() . '/' . $pdffile->get_component() . '/' .
                $pdffile->get_filearea() . '/' . $pdffile->get_itemid() . '/' . $pdffile->get_filename() .
                ''; // '?forcedownload=1';
            echo $OUTPUT->action_link($url, get_string('signinsheetpdfdownload', 'attendance', $pdffile->get_filename()));
        } else {
            echo $OUTPUT->notification(get_string('signinsheetpdferror', 'attendance', $list->name));
        }
        echo '<br />&nbsp;<br />';
        echo $OUTPUT->box_end();
        break;

    case mod_attendance_sessions_page_params::ACTION_UPLOAD:
        // We redirect if no list created.
        if (!signinsheet_partlist_created($att)) {
            redirect('participants.php?q='.$att->id, get_string('signinsheetcreatelistfirst', 'attendance'));
        }

        $lists = $DB->get_records_sql("
                SELECT *
                FROM {attendance_ss_p_lists}
                WHERE attendanceid = :attendanceid
                ORDER BY name ASC",
                array('attendanceid' => $att->id));

        $fs = get_file_storage();

        // We redirect if all pdf files are missing.
        $redirect = true;
        foreach ($lists as $list) {
            if ($list->filename && $file = find_pdf_file($context->id, $list->filename)) {
                $redirect = false;
            }
        }

        if ($redirect) {
            redirect('signinsheets.php?action=manage&amp;q=' . $signinsheet->id, get_string('signinsheetcreatepdffirst', 'attendance'));
        }

        // Print headers and tabs.
        echo $OUTPUT->heading_with_help(get_string('signinsheetuploadparticipants', 'attendance'), 'signinsheetparticipants', 'attendance');
        echo $OUTPUT->header();
        // Print the tabs.
        echo $output->header();
        echo $output->render($header);
        $tabs = new attendance_tabs($att, attendance_tabs::TAB_MANAGE);
        echo $output->render($tabs);

        $report = new participants_report();
        $importform = new signinsheet_participants_upload_form($thispageurl);

        $first = optional_param('first', 0, PARAM_INT); // Index of the last imported student.
        $numimports = optional_param('numimports', 0, PARAM_INT);
        $tempdir = optional_param('tempdir', 0, PARAM_PATH);

        if ($newfile = optional_param('newfile', '', PARAM_INT)) {
            if ($fromform = $importform->get_data()) {

                @raise_memory_limit('128M');
                $attendanceconfig->papergray = $att->papergray;

                $fileisgood = false;

                // Work out if this is an uploaded file
                // or one from the filesarea.
                $realfilename = $importform->get_new_filename('newfile');
                // Create a unique temp dir.
                $unique = str_replace('.', '', microtime(true) . rand(0, 100000));
                $tempdir = "{$CFG->tempdir}/attendance/import/$unique";
                check_dir_exists($tempdir, true, true);

                $importfile = $tempdir . '/' . $realfilename;

                if (!$result = $importform->save_file('newfile', $importfile, true)) {
                    throw new moodle_exception('uploadproblem');
                }

                $files = array();
                $mimetype = mimeinfo('type', $importfile);
                if ($mimetype == 'application/zip') {
                    $fp = get_file_packer('application/zip');
                    $files = $fp->extract_to_pathname($importfile, $tempdir);
                    if ($files) {
                        unlink($importfile);
                        $files = get_directory_list($tempdir);
                    } else {
                        echo $OUTPUT->notification(get_string('signinsheetcouldnotunzip', 'signinsheet_rimport', $realfilename),
                                                   'notifyproblem');

                    }
                } else if (preg_match('/^image/' , $mimetype)) {
                    $files[] = $realfilename;
                }
            }

            if (empty($files)) {
                $files = get_directory_list($tempdir);
            }

            $numpages = count($files);
            $last = $first + SIGNINSHEET_IMPORT_NUMUSERS - 1;
            if ($last > $numpages - 1) {
                $last = $numpages - 1;
            }
            $a = new stdClass();
            $a->from = $first + 1;
            $a->to = $last + 1;
            $a->total = $numpages;
            echo $OUTPUT->box_start();
            print_string('importfromto', 'attendance', $a);
            echo "<br />";
            echo $OUTPUT->box_end();
            echo $OUTPUT->box_start();

            $attendanceconfig->papergray = $attendance->papergray;

            for ($j = $first; $j <= $last; $j++) {
                $file = $files[$j];
                $filename = $tempdir . '/' . $file;
                set_time_limit(120);
                $scanner = new signinsheet_participants_scanner($attendance, $context->id, 0, 0);
                if ($scannedpage = $scanner->load_image($filename)) {
                    if ($scannedpage->status == 'ok') {
                        list($scanner, $scannedpage) = signinsheet_check_scanned_participants_page($att, $scanner, $scannedpage,
                                                                        $USER->id, $coursecontext, true);
                    }
                    if ($scannedpage->status == 'ok') {
                        $scannedpage = signinsheet_process_scanned_participants_page($att, $scanner, $scannedpage,
                                                                          $USER->id, $coursecontext);
                    }
                    if ($scannedpage->status == 'ok') {
                        $choicesdata = $DB->get_records('attendance_ss_p_choices', array('scannedppageid' => $scannedpage->id));
                        $scannedpage = $scannedpage = signinsheet_submit_scanned_participants_page($att, $scannedpage, $choicesdata);
                        if ($scannedpage->status == 'submitted') {
                            echo get_string('signinsheetpagenumberimported', 'attendance', $j)."<br /><br />";
                        }
                    }
                } else {
                    if ($scanner->ontop) {
                        $scannedpage->status = 'error';
                        $scannedpage->error = 'upsidedown';
                    }
                }
            }
            echo $OUTPUT->box_end();
            if ($last == $numpages - 1 or $numpages == 0) {
                if ($numimports) {
                    $OUTPUT->notification(get_string('signinsheetnumpages', 'attendance', $numimports), 'notifysuccess');
                } else {
                    $OUTPUT->notification(get_string('signinsheetnopages', 'attendance'));
                }
                remove_dir($tempdir);
                echo $OUTPUT->continue_button("$CFG->wwwroot/mod/attendance/signinsheets.php?q=$signinsheet->id&amp;action=upload");
                $OUTPUT->footer();
                die;
            } else {
                $first = $last + 1;
                redirect("$CFG->wwwroot/mod/attendance/signinsheets.php?q=$signinsheet->id&amp;" .
                        "action=upload&amp;tempdir=$tempdir&amp;first=$first&amp;numimports=$numimports&amp;sesskey=".sesskey());
            }
            $importform->display();
        } else if ($action == 'delete') {
            // Some pages need to be deleted.
            $pageids = optional_param_array('pageid', array(), PARAM_INT);
            foreach ($pageids as $pageid) {
                if ($pageid && $todelete = $DB->get_record('attendance_ss_scanned_pages', array('id' => $pageid))) {
                    $DB->delete_records('attendance_ss_scanned_pages', array('id' => $pageid));
                    $DB->delete_records('attendance_ss_p_choices', array('scannedppageid' => $pageid));
                }
            }
            $report->error_report($att, $course->id);
            $importform->display();
        } else {
            $report->error_report($att, $course->id);
            $importform->display();
        }
        break;
    default:
        // Invalid action specified.
        echo $OUTPUT->box_start('boxaligncenter generalbox boxwidthnormal');
        echo $OUTPUT->notification(get_string('signinsheetpdferror', 'attendance', ""));
}
// Finish the page.
if (!$download) {
    echo $OUTPUT->footer();
}