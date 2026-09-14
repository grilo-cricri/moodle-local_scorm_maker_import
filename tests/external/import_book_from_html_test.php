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
 * Testes da função externa import_book_from_html.
 *
 * @package    local_scorm_maker_import
 * @category   test
 * @copyright  2024 ScormMaker.com.br
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_scorm_maker_import\external\import_book_from_html
 */

namespace local_scorm_maker_import\external;

/**
 * Testes da função externa import_book_from_html.
 */
final class import_book_from_html_test extends \advanced_testcase {
    public function test_execute_rejects_user_without_capability(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        import_book_from_html::execute($course->id, '<h1>Chapter</h1><p>Content</p>');
    }

    public function test_execute_creates_book_with_chapters(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $html = '<p>Intro text</p><h1>Chapter One</h1><p>One</p><h1>Chapter Two</h1><p>Two</p>';

        $result = import_book_from_html::execute($course->id, $html, 'My Book', '<p>Book description</p>');

        $this->assertGreaterThan(0, $result['bookid']);
        $this->assertGreaterThan(0, $result['cmid']);
        $this->assertCount(3, $result['chapterids']);

        $chapters = $DB->get_records('book_chapters', ['bookid' => $result['bookid']], 'pagenum');
        $titles = array_values(array_map(static fn ($c) => $c->title, $chapters));
        $this->assertSame([
            get_string('bookintro', 'local_scorm_maker_import'),
            'Chapter One',
            'Chapter Two',
        ], $titles);
    }
}
