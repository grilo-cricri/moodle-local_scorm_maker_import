<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English language strings for local_scorm_maker_import.
 *
 * @package   local_scorm_maker_import
 * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['bookintro'] = 'Introduction';
$string['chapterimporterror'] = 'A chapter could not be created while importing the book: {$a}.';
$string['invalidcourse'] = 'Invalid course id: {$a}.';
$string['invaliddraftfile'] = 'The given draft file area must contain exactly one file.';
$string['invalidscormsource'] = 'Provide exactly one of "url" or "draftitemid" (not both, not neither).';
$string['invalidscormurl'] = 'The SCORM package URL must use HTTPS and have the exact host scormmaker.com.br.';
$string['invalidzip'] = 'The downloaded file is not a valid ZIP archive.';
$string['nobookmodule'] = 'The Book activity module (mod_book) is not installed or is disabled on this site.';
$string['nomanifest'] = 'The ZIP archive does not contain an imsmanifest.xml file at its root.';
$string['noscormmodule'] = 'The SCORM activity module (mod_scorm) is not installed or is disabled on this site.';
$string['pluginname'] = 'Scorm Maker Import';
$string['privacy:metadata'] = 'The Scorm Maker Import plugin does not store any personal data. It creates SCORM and Book activities on behalf of the calling web service user, but the data belonging to those activities is described by their own privacy providers.';
$string['scormdownloaderror'] = 'The SCORM package could not be downloaded from the given URL: {$a}.';
