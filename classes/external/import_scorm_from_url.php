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
 * External function to import a SCORM package from a URL or from an uploaded file.
 *
 * @package   local_scorm_maker_import
 * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_scorm_maker_import\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use local_scorm_maker_import\local\scorm_importer;
use moodle_exception;

/**
 * Imports a SCORM package (from an authorised HTTPS URL or from an uploaded file) into a course.
 *
 * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_scorm_from_url extends external_api {
    /**
     * Describes the parameters of import_scorm_from_url.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Id of the course to add the SCORM activity to'),
            'url' => new external_value(
                PARAM_URL,
                'HTTPS URL of the SCORM ZIP package on scormmaker.com.br. Required unless draftitemid is given instead.',
                VALUE_DEFAULT,
                ''
            ),
            'name' => new external_value(
                PARAM_TEXT,
                'Name of the SCORM activity; empty uses the defaultscormname language string',
                VALUE_DEFAULT,
                ''
            ),
            'sectionnum' => new external_value(PARAM_INT, 'Course section number to add the activity to', VALUE_DEFAULT, 0),
            'draftitemid' => new external_value(
                PARAM_INT,
                'Draft file area item id (from /webservice/upload.php) holding the SCORM ZIP. ' .
                    'Alternative to url; required unless url is given instead.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Gets a SCORM package (from a URL or from an uploaded draft file) and creates the activity from it.
     *
     * @param int $courseid Id of the course where the SCORM activity is created.
     * @param string $url HTTPS URL of the SCORM ZIP package on scormmaker.com.br. Required unless $draftitemid is given.
     * @param string $name Name of the SCORM activity; empty uses the defaultscormname language string.
     * @param int $sectionnum Number of the course section where the activity is added.
     * @param int $draftitemid Id of the draft area holding the SCORM ZIP. Alternative to $url.
     * @return array Associative array with scormid, cmid and warnings.
     */
    public static function execute(
        int $courseid,
        string $url = '',
        string $name = '',
        int $sectionnum = 0,
        int $draftitemid = 0
    ): array {
        [
            'courseid' => $courseid,
            'url' => $url,
            'name' => $name,
            'sectionnum' => $sectionnum,
            'draftitemid' => $draftitemid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'url' => $url,
            'name' => $name,
            'sectionnum' => $sectionnum,
            'draftitemid' => $draftitemid,
        ]);

        // Context_course::instance() already requires the course to exist (MUST_EXIST by default), so an invalid
        // courseid fails here with invalidcourse for any caller, before require_capability() runs. This does not
        // prevent id enumeration: an existing course without permission gives required_capability_exception, a
        // different code. Accepted, because course ids are not very sensitive.
        try {
            $context = \context_course::instance($courseid);
            require_capability('moodle/course:manageactivities', $context);
            self::validate_context($context);
            $course = get_course($courseid);
        } catch (\dml_exception) {
            throw new moodle_exception('invalidcourse', 'local_scorm_maker_import', '', $courseid);
        }

        scorm_importer::require_scorm_module_available();

        $hasurl = $url !== '';
        $hasdraftitem = $draftitemid > 0;
        if ($hasurl === $hasdraftitem) {
            // Neither or both were given; exactly one source is required.
            throw new moodle_exception('invalidscormsource', 'local_scorm_maker_import');
        }

        if ($name === '') {
            $name = get_string('defaultscormname', 'local_scorm_maker_import');
        }

        // Same limit as uploading the package through the SCORM activity form.
        $maxbytes = scorm_importer::max_package_bytes($course);
        $zippath = $hasurl
            ? scorm_importer::download_to_temp($url, $maxbytes)
            : scorm_importer::stage_file_from_draft($draftitemid, $maxbytes);

        try {
            scorm_importer::validate_manifest($zippath);
            $created = scorm_importer::create_scorm_activity($course, $zippath, $name, $sectionnum);
        } finally {
            scorm_importer::delete_temp_file($zippath);
        }

        return [
            'scormid' => $created->scormid,
            'cmid' => $created->cmid,
            'warnings' => [],
        ];
    }

    /**
     * Describes the return value of import_scorm_from_url.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'scormid' => new external_value(PARAM_INT, 'Id of the created SCORM instance'),
            'cmid' => new external_value(PARAM_INT, 'Id of the created course module'),
            'warnings' => new external_warnings(),
        ]);
    }
}
