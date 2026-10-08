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
 * Tests for the scorm_importer class.
 *
 * @package    local_scorm_maker_import
 * @category   test
 * @copyright  2024 ScormMaker.com.br
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_scorm_maker_import\local;

/**
 * Tests for the scorm_importer class.
 *
 * @covers \local_scorm_maker_import\local\scorm_importer
 */
final class scorm_importer_test extends \advanced_testcase {
    /**
     * Asserts that calling $callback throws a moodle_exception with the given errorcode.
     *
     * moodle_exception renders its message from a language string, so the errorcode is not
     * part of the message text and must be checked through the ->errorcode property.
     *
     * @param string $expectedcode Expected errorcode in moodle_exception::$errorcode.
     * @param callable $callback Code that must throw the exception.
     */
    private function assert_throws_errorcode(string $expectedcode, callable $callback): void {
        try {
            $callback();
        } catch (\moodle_exception $e) {
            $this->assertSame($expectedcode, $e->errorcode);
            return;
        }
        $this->fail("Expected moodle_exception with errorcode '$expectedcode' but none was thrown.");
    }

    public function test_require_scorm_module_available_throws_when_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        $DB->set_field('modules', 'visible', 0, ['name' => 'scorm']);

        $this->assert_throws_errorcode('noscormmodule', function (): void {
            scorm_importer::require_scorm_module_available();
        });
    }

    public function test_require_scorm_module_available_passes_when_enabled(): void {
        global $DB;
        $this->resetAfterTest();

        $DB->set_field('modules', 'visible', 1, ['name' => 'scorm']);

        scorm_importer::require_scorm_module_available();
        $this->assertTrue(true);
    }

    public function test_validate_manifest_accepts_zip_with_root_manifest(): void {
        global $CFG;
        $this->resetAfterTest();

        scorm_importer::validate_manifest($CFG->dirroot . '/mod/scorm/tests/packages/singlescobasic.zip');
        $this->assertTrue(true);
    }

    public function test_validate_manifest_rejects_zip_without_manifest(): void {
        global $CFG;
        $this->resetAfterTest();

        $this->assert_throws_errorcode('nomanifest', function () use ($CFG): void {
            scorm_importer::validate_manifest($CFG->dirroot . '/mod/scorm/tests/packages/invalid.zip');
        });
    }

    public function test_validate_manifest_rejects_manifest_not_at_root(): void {
        global $CFG;
        $this->resetAfterTest();

        $this->assert_throws_errorcode('nomanifest', function () use ($CFG): void {
            scorm_importer::validate_manifest($CFG->dirroot . '/mod/scorm/tests/packages/badscorm.zip');
        });
    }

    public function test_validate_manifest_rejects_non_zip_file(): void {
        $this->resetAfterTest();

        $path = make_request_directory() . '/notazip.zip';
        file_put_contents($path, 'this is not a zip file');

        $this->assert_throws_errorcode('invalidzip', function () use ($path): void {
            scorm_importer::validate_manifest($path);
        });
    }

    public function test_download_to_temp_rejects_unsupported_protocol(): void {
        $this->resetAfterTest();

        $this->assert_throws_errorcode('invalidscormurl', function (): void {
            scorm_importer::download_to_temp('ftp://scormmaker.com.br/package.zip', 1048576);
        });
    }

    public function test_validate_download_url_accepts_allowed_https_origin(): void {
        scorm_importer::validate_download_url('https://scormmaker.com.br/packages/course.zip');
        $this->assertTrue(true);
    }

    /**
     * Asserts that the remote URL is restricted to HTTPS and the exact allowed host.
     *
     * @dataProvider disallowed_download_url_provider
     * @param string $url URL that must be rejected.
     */
    public function test_validate_download_url_rejects_urls_outside_allowed_origin(string $url): void {
        $this->assert_throws_errorcode('invalidscormurl', function () use ($url): void {
            scorm_importer::validate_download_url($url);
        });
    }

    /**
     * Provides URLs that do not belong to the allowed origin.
     *
     * @return array<string, array{string}>
     */
    public static function disallowed_download_url_provider(): array {
        return [
            'http scheme' => ['http://scormmaker.com.br/package.zip'],
            'different host' => ['https://evil.example/package.zip'],
            'subdomain' => ['https://files.scormmaker.com.br/package.zip'],
            'host suffix confusion' => ['https://scormmaker.com.br.evil.example/package.zip'],
            'nonstandard port' => ['https://scormmaker.com.br:8443/package.zip'],
            'userinfo host confusion' => ['https://scormmaker.com.br@evil.example/package.zip'],
            'trailing dot host' => ['https://scormmaker.com.br./package.zip'],
        ];
    }

    public function test_stage_file_from_draft_copies_single_file(): void {
        global $CFG, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        get_file_storage()->create_file_from_pathname([
            'component' => 'user',
            'filearea'  => 'draft',
            'contextid' => $usercontext->id,
            'itemid'    => $draftitemid,
            'filepath'  => '/',
            'filename'  => 'package.zip',
        ], $CFG->dirroot . '/mod/scorm/tests/packages/singlescobasic.zip');

        $stagedpath = scorm_importer::stage_file_from_draft($draftitemid, 1048576);

        $this->assertFileExists($stagedpath);
        scorm_importer::validate_manifest($stagedpath);
        scorm_importer::delete_temp_file($stagedpath);
    }

    public function test_stage_file_from_draft_rejects_empty_draft_area(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $emptydraftitemid = file_get_unused_draft_itemid();

        $this->assert_throws_errorcode('invaliddraftfile', function () use ($emptydraftitemid): void {
            scorm_importer::stage_file_from_draft($emptydraftitemid, 1048576);
        });
    }

    public function test_create_scorm_activity_creates_module(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $zippath = $CFG->dirroot . '/mod/scorm/tests/packages/singlescobasic.zip';
        $result = scorm_importer::create_scorm_activity($course, $zippath, 'My SCORM', 0);

        $this->assertGreaterThan(0, $result->scormid);
        $this->assertGreaterThan(0, $result->cmid);

        $scorm = $DB->get_record('scorm', ['id' => $result->scormid], '*', MUST_EXIST);
        $this->assertSame('My SCORM', $scorm->name);

        $cm = get_coursemodule_from_id('scorm', $result->cmid, $course->id, false, MUST_EXIST);
        $this->assertEquals($result->scormid, $cm->instance);
        $this->assertSame('', $cm->idnumber);
    }

    public function test_delete_temp_file_ignores_missing_file(): void {
        scorm_importer::delete_temp_file('');
        scorm_importer::delete_temp_file(make_request_directory() . '/missing.zip');
        $this->assertTrue(true);
    }

    public function test_stage_file_from_draft_rejects_file_above_limit(): void {
        global $CFG, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $CFG->dirroot . '/mod/scorm/tests/packages/singlescobasic.zip';
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname([
            'component' => 'user',
            'filearea'  => 'draft',
            'contextid' => \context_user::instance($USER->id)->id,
            'itemid'    => $draftitemid,
            'filepath'  => '/',
            'filename'  => 'package.zip',
        ], $source);

        $this->assert_throws_errorcode('packagetoolarge', function () use ($draftitemid, $source): void {
            scorm_importer::stage_file_from_draft($draftitemid, filesize($source) - 1);
        });
    }

    public function test_require_size_within_limit(): void {
        scorm_importer::require_size_within_limit(100, 100);

        $this->assert_throws_errorcode('packagetoolarge', function (): void {
            scorm_importer::require_size_within_limit(101, 100);
        });
    }

    public function test_progress_limiter_aborts_only_above_limit(): void {
        $limiter = scorm_importer::progress_limiter(100);

        $this->assertSame(0, $limiter(null, 0, 0));
        $this->assertSame(0, $limiter(null, 100, 100));
        $this->assertSame(1, $limiter(null, 101, 0), 'Declared size above the limit.');
        $this->assertSame(1, $limiter(null, 0, 101), 'Undeclared size, downloaded bytes above the limit.');
    }

    public function test_max_package_bytes_follows_course_limit(): void {
        $this->resetAfterTest();

        set_config('maxbytes', 0);
        $course = $this->getDataGenerator()->create_course(['maxbytes' => 1024]);
        $this->assertSame(1024, scorm_importer::max_package_bytes($course));

        set_config('maxbytes', 512);
        $this->assertSame(512, scorm_importer::max_package_bytes($course));
    }
}
