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
 * Creates the PDF forms for attendance signin sheets
 *
 * @package       mod
 * @subpackage    attendance
 * @author        Juergen Zimmer <zimmerj7@univie.ac.at>
 * @copyright     2015 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @since         Moodle 2.2+
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->libdir . '/pdflib.php');

define('LOGO_MAX_ASPECT_RATIO',3.714285714);

class signinsheet_pdf extends pdf
{
    /**
     * Containing the current page buffer after checkpoint() was called.
     */
    private $checkpoint;

    public function checkpoint() {
        $this->checkpoint = $this->getPageBuffer($this->page);
    }

    public function backtrack() {
        $this->setPageBuffer($this->page, $this->checkpoint);
    }

    public function is_overflowing() {
        return $this->y > $this->PageBreakTrigger;
    }

    public function set_title($newtitle) {
        $this->title = $newtitle;
    }

}

class signinsheet_participants_pdf extends signinsheet_pdf
{
    public $listno;

    /**
     * (non-PHPdoc)
     * @see TCPDF::Header()
     */
    public function Header() {
        global $CFG,  $DB;

        $this->Line(11,  12,  14, 12);
        $this->Line(12.5, 10.5, 12.5, 13.5);
        $this->Line(193, 12, 196, 12);
        $this->Line(194.5, 10.5, 194.5, 13.5);

        $this->Line(12.5, 18, 18.5, 12);

        $this->SetFont('FreeSans', 'I', 8);

        // Title.
        $x = $this->GetX();
        $y = $this->GetY();
        $this->SetXY($x + 9, $y + 5.5);
        if (!empty($this->title)) {
            $this->Cell(110, 15, $this->title, 0, 1, 'L');
        }

        $this->SetXY($x, $y);
        $this->Rect(15, 23, 175, 0.3, 'F');
        // Line break.
        $this->Ln(26);

        $this->Cell(10, 3.5, '', 0, 0, 'C');
        $this->Cell(3.5, 3.5, '', 1, 0, 'C');
        $this->Image($CFG->dirroot . '/mod/attendance/pix/kreuz.gif', $this->GetX() - 3.3, $this->Gety() + 0.2, 3.15, 0);
        $this->SetFont('FreeSans', 'B', 10);
        $this->Cell(31, 3.5, "", 0, 0, 'L');
        $this->Cell(55, 3.5, get_string('lastname'), 0, 0, 'L');
        $this->Cell(60, 3.5, get_string('firstname'), 0, 1, 'L');
        $this->Rect(15, ($this->GetY() + 1), 175, 0.3, 'F');
        $this->Ln(4.5);
        $x = $this->GetX();
        $y = $this->GetY();
        $this->Rect(145, 8, 25, 13);     // Square for the teachers to sign.

        $this->SetXY(145.5, 6.5);
        $this->SetFont('FreeSans', '', 8);
        $this->Cell(29, 7, get_string('lecturer', 'attendance'), 0, 0, 'L');

        $this->SetXY($x, $y);
    }

    /**
     * (non-PHPdoc)
     * @see TCPDF::Footer()
     */
    public function Footer() {
        $this->Line(11, 285, 14, 285);
        $this->Line(12.5, 283.5, 12.5, 286.5);
        $this->Line(193, 285, 196, 285);
        $this->Line(194.5, 283.5, 194.5, 286.5);
        $this->Rect(192, 282.5, 2.5, 2.5, 'F');                // Flip indicator.
        $this->Rect(15, 281, 175, 0.5, 'F');

        // Position at 1.7 cm from bottom.
        $this->SetY(-17);
        // FreeSans italic 8.
        $this->SetFont('FreeSans', 'I', 8);
        // Page number.
        $this->Cell(0, 10,
                    get_string('page') . ' ' .
                             $this->getAliasNumPage().'/' . $this->getAliasNbPages() .
                             ' ( '.$this->listno.' )', 0, 0, 'C');
        // Print barcode for list.
        $value = substr('000000000000000000000000'.base_convert($this->listno, 10, 2), -25);
        $y = $this->GetY() - 5;
        $x = 170;
        $this->Rect($x, $y, 0.2, 3.5, 'F');
        $this->Rect($x, $y, 0.7, 0.2, 'F');
        $this->Rect($x, $y + 3.5, 0.7, 0.2, 'F');
        $x += 0.7;
        for ($i = 0; $i < 25; $i++) {
            if ($value[$i] == '1') {
                $this->Rect($x, $y, 0.7, 3.5, 'F');
                $this->Rect($x, $y, 1.2, 0.2, 'F');
                $this->Rect($x, $y + 3.5, 1.2, 0.2, 'F');
                $x += 1;
            } else {
                $this->Rect($x, $y, 0.2, 3.5, 'F');
                $this->Rect($x, $y, 0.7, 0.2, 'F');
                $this->Rect($x, $y + 3.5, 0.7, 0.2, 'F');
                $x += 0.7;
            }
        }
        $this->Rect($x, $y, 0.2, 3.7, 'F');
    }
}

/**
 * Creates a PDF document for a list of participants
 *
 * @param mod_attendance_structure $att
 * @param int $courseid
 * @param attendance_take_data $participants
 * @param stdClass $list
 * @param context $context
 * @return boolean|stored_file
 */
function signinsheet_create_pdf_participants(mod_attendance_structure $att, int $courseid, attendance_take_data $participants, stdClass $list = null, context $context) {
    global $CFG, $DB;

    $coursecontext = context_course::instance($courseid); // Course context.
    $systemcontext = context_system::instance();

    $attendanceconfig = get_config('attendance');

    if (empty($participants)) {
        return false;
    }

    $pdf = new signinsheet_participants_pdf('P', 'mm', 'A4');
    $pdf->listno = $list->number;
    $title = $att->course->fullname . ', ' . $att->name;
    $pdf->set_title($title);
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    $pdf->Ln(9);
    $pdf->formtype = 4;
    $pdf->colwidth = 7 * 6.5;

    $position = 1;

    $pdf->SetFont('FreeSans', '', 10);
    foreach ($participants as $participant) {
        $pdf->Cell(9, 3.5, "$position. ", 0, 0, 'R');
        $pdf->Cell(1, 3.5, '', 0, 0, 'C');
        $x = $pdf->GetX();
        $y = $pdf->GetY();

        for ($i = 1; $i <= 4; $i++) {
            // Move the boxes slightly down to align with question number.
            $pdf->Rect($x, $y + 0.6, 3.5, 3.5, '', array('all' => array('width' => 0.2)));
            $x += 6.5;
        }

        $pdf->Cell(3, 3.5, '', 0, 0, 'C');

        $pdf->Cell(6, 3.5, '', 0, 0, 'C');
        // $userkey = substr($participant->{$attendanceconfig->ID_field},
        //                   strlen($attendanceconfig->ID_prefix), $attendanceconfig->ID_digits);
        $userkey = strval($participant->id);
        $pdf->Cell(13, 3.5, $userkey, 0, 0, 'R');
        $pdf->Cell(12, 3.5, '', 0, 0, 'L');
        if ($pdf->GetStringWidth($participant->firstname) > 40) {
            $participant->firstname = substr($participant->firstname, 0, 20);
        }
        if ($pdf->GetStringWidth($participant->lastname) > 55) {
            $participant->lastname = substr($participant->lastname, 0, 25);
        }
        $pdf->Cell(55, 3.5, $participant->lastname, 0, 0, 'L');
        $pdf->Cell(40, 3.5, $participant->firstname, 0, 0, 'L');
        $pdf->Cell(10, 3.5, '', 0, 1, 'R');
        // Print barcode.
        $value = substr('000000000000000000000000'.base_convert($participant->id, 10, 2), -25);
        $y = $pdf->GetY() - 3.5;
        $x = 170;
        $pdf->Rect($x, $y, 0.2, 3.5, 'F');
        $pdf->Rect($x, $y, 0.7, 0.2, 'F');
        $pdf->Rect($x, $y + 3.5, 0.7, 0.2, 'F');
        $x += 0.7;
        for ($i = 0; $i < 25; $i++) {
            if ($value[$i] == '1') {
                $pdf->Rect($x, $y, 0.7, 3.5, 'F');
                $pdf->Rect($x, $y, 1.2, 0.2, 'F');
                $pdf->Rect($x, $y + 3.5, 1.2, 0.2, 'F');
                $x += 1.2;
            } else {
                $pdf->Rect($x, $y, 0.2, 3.5, 'F');
                $pdf->Rect($x, $y, 0.7, 0.2, 'F');
                $pdf->Rect($x, $y + 3.5, 0.7, 0.2, 'F');
                $x += 0.7;
            }
        }
        $pdf->Rect($x, $y, 0.2, 3.7, 'F');
        $pdf->Rect(15, ($pdf->GetY() + 1), 175, 0.2, 'F');
        if ($position % NUMBERS_PER_PAGE != 0) {
            $pdf->Ln(3.6);
        } else {
            $pdf->AddPage();
            $pdf->Ln(9);
        }
        $position++;
    }

    $fs = get_file_storage();

    // Prepare file record object.
    $date = usergetdate(time());
    $timestamp = sprintf('%04d%02d%02d_%02d%02d%02d',
            $date['year'], $date['mon'], $date['mday'], $date['hours'], $date['minutes'], $date['seconds']);

    $fileprefix = get_string('signinsheetfileprefixparticipants', 'attendance');
    $fileinfo = array(
            'contextid' => $context->id,
            'component' => 'mod_attendance',
            'filearea' => 'participants',
            'filepath' => '/',
            'itemid' => 0,
            'filename' => $fileprefix . '_' . $list->id . '_' . $timestamp . '.pdf');

    if ($oldfile = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
            $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename'])) {
        $oldfile->delete();
    }

    $pdfstring = $pdf->Output('', 'S');
    $file = $fs->create_file_from_string($fileinfo, $pdfstring);
    return $file;
}
