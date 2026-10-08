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
 * Testes da função externa import_scorm_from_url.
 *
 * @package    local_scorm_maker_import
 * @category   test
 * @copyright  2024 ScormMaker.com.br
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_scorm_maker_import\external;

/**
 * Testes da função externa import_scorm_from_url.
 *
 * @covers \local_scorm_maker_import\external\import_scorm_from_url
 */
final class import_scorm_from_url_test extends \advanced_testcase {
    public function test_execute_rejects_user_without_capability(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        import_scorm_from_url::execute($course->id, 'https://scormmaker.com.br/package.zip');
    }

    public function test_execute_rejects_invalid_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $thrown = null;
        try {
            import_scorm_from_url::execute(0, 'https://scormmaker.com.br/package.zip');
        } catch (\moodle_exception $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown);
        $this->assertSame('invalidcourse', $thrown->errorcode);
    }

    /**
     * Um chamador sem a capability não pode conseguir distinguir "curso não existe" de
     * "curso existe mas eu não tenho a capability" pelo tipo de exceção — essa distinção
     * permitiria a um portador de token enumerar quais ids de curso existem no site.
     */
    public function test_execute_rejects_invalid_course_identically_without_capability(): void {
        $this->resetAfterTest();

        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $thrown = null;
        try {
            import_scorm_from_url::execute(0, 'https://scormmaker.com.br/package.zip');
        } catch (\moodle_exception $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown);
        $this->assertSame('invalidcourse', $thrown->errorcode);
    }

    public function test_execute_rejects_when_neither_url_nor_draftitemid_given(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $thrown = null;
        try {
            import_scorm_from_url::execute($course->id);
        } catch (\moodle_exception $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown);
        $this->assertSame('invalidscormsource', $thrown->errorcode);
    }

    public function test_execute_rejects_when_both_url_and_draftitemid_given(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $thrown = null;
        try {
            import_scorm_from_url::execute($course->id, 'https://scormmaker.com.br/package.zip', 'SCORM', 0, 123);
        } catch (\moodle_exception $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown);
        $this->assertSame('invalidscormsource', $thrown->errorcode);
    }

    public function test_execute_rejects_url_outside_allowed_origin(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $thrown = null;
        try {
            import_scorm_from_url::execute($course->id, 'https://evil.example/package.zip');
        } catch (\moodle_exception $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertSame('invalidscormurl', $thrown->errorcode);
    }

    public function test_execute_creates_scorm_from_draftitemid(): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

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

        $result = import_scorm_from_url::execute($course->id, '', 'Uploaded SCORM', 0, $draftitemid);

        $this->assertGreaterThan(0, $result['scormid']);
        $scorm = $DB->get_record('scorm', ['id' => $result['scormid']], '*', MUST_EXIST);
        $this->assertSame('Uploaded SCORM', $scorm->name);
    }

    /**
     * Stores the basic SCORM test package in a new draft area of the current user.
     *
     * @return int Draft item id.
     */
    private function draft_with_basic_package(): int {
        global $CFG, $USER;

        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname([
            'component' => 'user',
            'filearea'  => 'draft',
            'contextid' => \context_user::instance($USER->id)->id,
            'itemid'    => $draftitemid,
            'filepath'  => '/',
            'filename'  => 'package.zip',
        ], $CFG->dirroot . '/mod/scorm/tests/packages/singlescobasic.zip');

        return $draftitemid;
    }

    /**
     * A package above the course upload limit is refused, as in the SCORM activity form, and no activity is created.
     */
    public function test_execute_rejects_package_above_course_upload_limit(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('maxbytes', 0);
        $course = $this->getDataGenerator()->create_course(['maxbytes' => 1024]);

        $thrown = null;
        try {
            import_scorm_from_url::execute($course->id, '', 'Too large', 0, $this->draft_with_basic_package());
        } catch (\moodle_exception $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown);
        $this->assertSame('packagetoolarge', $thrown->errorcode);
        $this->assertSame(0, $DB->count_records('scorm', ['course' => $course->id]));
    }

    public function test_execute_without_name_uses_language_string(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $result = import_scorm_from_url::execute($course->id, '', '', 0, $this->draft_with_basic_package());

        $scorm = $DB->get_record('scorm', ['id' => $result['scormid']], '*', MUST_EXIST);
        $this->assertSame(get_string('defaultscormname', 'local_scorm_maker_import'), $scorm->name);
    }
}
