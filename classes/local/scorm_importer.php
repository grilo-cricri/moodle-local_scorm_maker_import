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
 * SCORM package import logic for the local_scorm_maker_import plugin.
 *
 * @package   local_scorm_maker_import
 * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_scorm_maker_import\local;

use moodle_exception;
use stdClass;

/**
 * Gets a SCORM package (from a URL or a draft area), validates it and creates the activity from it.
 *
 * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_importer {
    /** @var string Scheme permitted for remote SCORM package downloads. */
    private const ALLOWED_DOWNLOAD_SCHEME = 'https';

    /** @var string Host permitted for remote SCORM package downloads. */
    private const ALLOWED_DOWNLOAD_HOST = 'scormmaker.com.br';

    /** @var int Port permitted for remote SCORM package downloads. */
    private const ALLOWED_DOWNLOAD_PORT = 443;

    /** @var int cURL error returned when a declared Content-Length exceeds CURLOPT_MAXFILESIZE. */
    private const CURLE_FILESIZE_EXCEEDED = 63;

    /** @var int cURL error returned when the progress callback aborts the transfer. */
    private const CURLE_ABORTED_BY_CALLBACK = 42;

    /**
     * Checks that mod_scorm is installed and enabled on this site.
     *
     * @throws moodle_exception if mod_scorm is missing or disabled.
     */
    public static function require_scorm_module_available(): void {
        global $DB;

        $module = $DB->get_record('modules', ['name' => 'scorm']);
        if (!$module || empty($module->visible)) {
            throw new moodle_exception('noscormmodule', 'local_scorm_maker_import');
        }
    }

    /**
     * Returns the largest package size accepted for the given course.
     *
     * This is the same limit a teacher gets when uploading a package through the activity form: the site and course
     * maximum upload sizes, capped by the PHP upload limits.
     *
     * @param stdClass $course Course record.
     * @return int Maximum size in bytes.
     */
    public static function max_package_bytes(stdClass $course): int {
        global $CFG;

        return (int) get_max_upload_file_size($CFG->maxbytes, $course->maxbytes ?? 0);
    }

    /**
     * Returns the path of a new, unique temporary ZIP file inside a request directory.
     *
     * @return string Absolute path of the (not yet created) temporary file.
     */
    protected static function new_temp_path(): string {
        return make_request_directory() . '/' . uniqid('package_', true) . '.zip';
    }

    /**
     * Checks that the remote URL belongs exactly to the authorised origin.
     *
     * The host comparison is exact on purpose: subdomains, look-alike host suffixes, embedded credentials and
     * alternative ports are not allowed.
     *
     * @param string $url URL to validate.
     * @throws moodle_exception if the URL does not use the authorised origin.
     */
    public static function validate_download_url(string $url): void {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new moodle_exception('invalidscormurl', 'local_scorm_maker_import');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        $hasuserinfo = isset($parts['user']) || isset($parts['pass']);

        if (
            $scheme !== self::ALLOWED_DOWNLOAD_SCHEME
                || $host !== self::ALLOWED_DOWNLOAD_HOST
                || ($port !== null && (int) $port !== self::ALLOWED_DOWNLOAD_PORT)
                || $hasuserinfo
        ) {
            throw new moodle_exception('invalidscormurl', 'local_scorm_maker_import');
        }
    }

    /**
     * Rejects a package larger than the allowed size.
     *
     * @param int $size Size of the package in bytes.
     * @param int $maxbytes Maximum size in bytes (see max_package_bytes()).
     * @throws moodle_exception packagetoolarge if $size is above $maxbytes.
     */
    public static function require_size_within_limit(int $size, int $maxbytes): void {
        if ($size > $maxbytes) {
            throw new moodle_exception('packagetoolarge', 'local_scorm_maker_import', '', display_size($maxbytes));
        }
    }

    /**
     * Downloads a remote ZIP file into a unique temporary file.
     *
     * The transfer stops as soon as it goes over $maxbytes, whether or not the server sends a Content-Length header.
     *
     * @param string $url HTTPS URL of the ZIP package on the authorised host.
     * @param int $maxbytes Maximum size of the package in bytes (see max_package_bytes()).
     * @return string Absolute path of the downloaded temporary file.
     * @throws moodle_exception if the package is too large or the download fails for any other reason.
     */
    public static function download_to_temp(string $url, int $maxbytes): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        self::validate_download_url($url);

        $tempfile = self::new_temp_path();

        $filehandle = fopen($tempfile, 'wb');
        if ($filehandle === false) {
            throw new moodle_exception('scormdownloaderror', 'local_scorm_maker_import', '', $url);
        }

        $curl = new \curl();
        try {
            // Redirects are disabled so the authorised origin cannot send the Moodle server to another host.
            // CURLOPT_MAXFILESIZE refuses a declared Content-Length above the limit before the body is written, and
            // the progress callback aborts chunked or undeclared transfers as soon as they go over it.
            $curl->get($url, null, [
                'CURLOPT_SSL_VERIFYPEER' => true,
                'CURLOPT_SSL_VERIFYHOST' => 2,
                'CURLOPT_FOLLOWLOCATION' => false,
                'CURLOPT_MAXREDIRS' => 0,
                'CURLOPT_CONNECTTIMEOUT' => 20,
                'CURLOPT_TIMEOUT' => 300,
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_NOBODY' => false,
                'CURLOPT_FILE' => $filehandle,
                'CURLOPT_MAXFILESIZE' => $maxbytes,
                'CURLOPT_NOPROGRESS' => false,
                'CURLOPT_PROGRESSFUNCTION' => self::progress_limiter($maxbytes),
            ]);
        } finally {
            fclose($filehandle);
        }

        @chmod($tempfile, $CFG->filepermissions);

        $errno = $curl->get_errno();
        $toolarge = $errno === self::CURLE_FILESIZE_EXCEEDED
            || $errno === self::CURLE_ABORTED_BY_CALLBACK
            || (is_readable($tempfile) && filesize($tempfile) > $maxbytes);
        if ($toolarge) {
            self::delete_temp_file($tempfile);
            throw new moodle_exception('packagetoolarge', 'local_scorm_maker_import', '', display_size($maxbytes));
        }

        $info = $curl->get_info();
        $status = is_array($info) ? (int) ($info['http_code'] ?? 0) : 0;

        if ($errno !== 0 || $status !== 200 || !is_readable($tempfile)) {
            self::delete_temp_file($tempfile);
            throw new moodle_exception('scormdownloaderror', 'local_scorm_maker_import', '', $url);
        }

        return $tempfile;
    }

    /**
     * Builds the cURL progress callback that aborts a download once it goes over the size limit.
     *
     * @param int $maxbytes Maximum size of the package in bytes.
     * @return callable Callback for CURLOPT_PROGRESSFUNCTION; a non-zero return aborts the transfer.
     */
    public static function progress_limiter(int $maxbytes): callable {
        return static function ($handle, $downloadtotal, $downloaded) use ($maxbytes): int {
            return ($downloadtotal > $maxbytes || $downloaded > $maxbytes) ? 1 : 0;
        };
    }

    /**
     * Copies the only file found in the current user's draft area into a unique temporary file.
     *
     * Used instead of download_to_temp() when the caller uploaded the ZIP through /webservice/upload.php rather than
     * pointing to an authorised HTTPS URL. Draft areas always belong to the current user's own context ($USER), so
     * this cannot be used to read another user's files.
     *
     * @param int $draftitemid Draft area item id returned by /webservice/upload.php.
     * @param int $maxbytes Maximum size of the package in bytes (see max_package_bytes()).
     * @return string Absolute path of the copied temporary file.
     * @throws moodle_exception if the draft area is empty, holds more than one file, or the file is too large.
     */
    public static function stage_file_from_draft(int $draftitemid, int $maxbytes): string {
        global $USER;

        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);

        if (count($files) !== 1) {
            throw new moodle_exception('invaliddraftfile', 'local_scorm_maker_import');
        }

        $file = reset($files);
        self::require_size_within_limit((int) $file->get_filesize(), $maxbytes);

        $tempfile = self::new_temp_path();
        $file->copy_content_to($tempfile);

        return $tempfile;
    }

    /**
     * Confirms that the ZIP at the given path contains an imsmanifest.xml file at its root.
     *
     * @param string $zippath Absolute path of the downloaded ZIP file.
     * @throws moodle_exception if the file is not a valid ZIP or has no manifest at its root.
     */
    public static function validate_manifest(string $zippath): void {
        $zip = new \ZipArchive();
        $result = $zip->open($zippath);
        if ($result !== true) {
            throw new moodle_exception('invalidzip', 'local_scorm_maker_import');
        }

        // FL_NODIR is deliberately omitted: the manifest must be at the archive root, not in a subfolder.
        $hasmanifest = $zip->locateName('imsmanifest.xml', \ZipArchive::FL_NOCASE) !== false;
        $zip->close();

        if (!$hasmanifest) {
            throw new moodle_exception('nomanifest', 'local_scorm_maker_import');
        }
    }

    /**
     * Deletes a temporary file, ignoring files that do not exist.
     *
     * @param string $path Absolute path of the temporary file.
     */
    public static function delete_temp_file(string $path): void {
        if ($path !== '' && file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Creates a SCORM activity in the given course from a local ZIP file.
     *
     * @param stdClass $course Course record.
     * @param string $zippath Absolute path of the already validated ZIP file.
     * @param string $name Activity name.
     * @param int $sectionnum Number of the section where the activity will be placed.
     * @return stdClass Object with ->scormid and ->cmid.
     */
    public static function create_scorm_activity(stdClass $course, string $zippath, string $name, int $sectionnum): stdClass {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/scorm/lib.php');
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        [$module] = can_add_moduleinfo($course, 'scorm', $sectionnum);

        $usercontext = \context_user::instance($USER->id);
        $draftitemid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $fs->create_file_from_pathname([
            'component' => 'user',
            'filearea'  => 'draft',
            'contextid' => $usercontext->id,
            'itemid'    => $draftitemid,
            'filepath'  => '/',
            'filename'  => basename($zippath),
        ], $zippath);

        $cfgscorm = get_config('scorm');

        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'scorm';
        $moduleinfo->module = $module->id;
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->name = $name;
        $moduleinfo->intro = '';
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->showdescription = 0;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;

        $moduleinfo->scormtype = SCORM_TYPE_LOCAL;
        $moduleinfo->packagefile = $draftitemid;
        $moduleinfo->reference = basename($zippath);
        $moduleinfo->updatefreq = SCORM_UPDATE_NEVER;
        $moduleinfo->popup = 0;
        $moduleinfo->width = $cfgscorm->framewidth;
        $moduleinfo->height = $cfgscorm->frameheight;
        $moduleinfo->skipview = $cfgscorm->skipview;
        $moduleinfo->hidebrowse = $cfgscorm->hidebrowse;
        $moduleinfo->displaycoursestructure = $cfgscorm->displaycoursestructure;
        $moduleinfo->hidetoc = $cfgscorm->hidetoc;
        $moduleinfo->nav = $cfgscorm->nav;
        $moduleinfo->navpositionleft = $cfgscorm->navpositionleft;
        $moduleinfo->navpositiontop = $cfgscorm->navpositiontop;
        $moduleinfo->displayattemptstatus = $cfgscorm->displayattemptstatus;
        $moduleinfo->timeopen = 0;
        $moduleinfo->timeclose = 0;
        $moduleinfo->grademethod = GRADESCOES;
        $moduleinfo->maxgrade = $cfgscorm->maxgrade;
        $moduleinfo->maxattempt = $cfgscorm->maxattempt;
        $moduleinfo->whatgrade = $cfgscorm->whatgrade;
        $moduleinfo->forcenewattempt = $cfgscorm->forcenewattempt;
        $moduleinfo->lastattemptlock = $cfgscorm->lastattemptlock;
        $moduleinfo->forcecompleted = $cfgscorm->forcecompleted;
        $moduleinfo->masteryoverride = $cfgscorm->masteryoverride;
        $moduleinfo->auto = $cfgscorm->auto;

        $createdmoduleinfo = add_moduleinfo($moduleinfo, $course);

        $result = new stdClass();
        $result->scormid = (int) $createdmoduleinfo->instance;
        $result->cmid = (int) $createdmoduleinfo->coursemodule;

        return $result;
    }
}
