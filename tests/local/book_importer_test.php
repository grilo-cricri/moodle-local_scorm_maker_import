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
 * Testes da classe book_importer.
 *
 * @package    local_scorm_maker_import
 * @category   test
 * @copyright  2024 ScormMaker.com.br
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_scorm_maker_import\local\book_importer
 */

namespace local_scorm_maker_import\local;

/**
 * Testes da classe book_importer.
 */
final class book_importer_test extends \advanced_testcase {
    public function test_split_html_by_h1_with_intro_and_two_chapters(): void {
        $html = '<p>Welcome text</p>'
            . '<h1>Chapter One</h1><p>Content one</p>'
            . '<h1>Chapter Two</h1><p>Content two</p><ul><li>item</li></ul>';

        $chapters = book_importer::split_html_by_h1($html, 'Fallback title');

        $this->assertCount(3, $chapters);
        $this->assertSame(get_string('bookintro', 'local_scorm_maker_import'), $chapters[0]['title']);
        $this->assertSame('<p>Welcome text</p>', $chapters[0]['content']);
        $this->assertSame('Chapter One', $chapters[1]['title']);
        $this->assertSame('<p>Content one</p>', $chapters[1]['content']);
        $this->assertSame('Chapter Two', $chapters[2]['title']);
        $this->assertSame('<p>Content two</p><ul><li>item</li></ul>', $chapters[2]['content']);
    }

    public function test_split_html_by_h1_without_intro(): void {
        $html = '<h1>Only Chapter</h1><p>Body</p>';

        $chapters = book_importer::split_html_by_h1($html, 'Fallback title');

        $this->assertCount(1, $chapters);
        $this->assertSame('Only Chapter', $chapters[0]['title']);
        $this->assertSame('<p>Body</p>', $chapters[0]['content']);
    }

    public function test_split_html_by_h1_without_any_heading_returns_single_chapter(): void {
        $html = '<p>Just a paragraph, no headings at all.</p>';

        $chapters = book_importer::split_html_by_h1($html, 'Fallback title');

        $this->assertCount(1, $chapters);
        $this->assertSame('Fallback title', $chapters[0]['title']);
        $this->assertSame($html, $chapters[0]['content']);
    }

    public function test_split_html_by_h1_strips_inner_markup_from_title(): void {
        $html = '<h1><strong>Bold</strong> and <em>emphasised</em> title</h1><p>Content</p>';

        $chapters = book_importer::split_html_by_h1($html, 'Fallback title');

        $this->assertSame('Bold and emphasised title', $chapters[0]['title']);
    }

    public function test_require_book_module_available_throws_when_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        $DB->set_field('modules', 'visible', 0, ['name' => 'book']);

        $thrown = null;
        try {
            book_importer::require_book_module_available();
        } catch (\moodle_exception $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown);
        $this->assertSame('nobookmodule', $thrown->errorcode);
    }

    public function test_create_book_activity_and_chapters(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $created = book_importer::create_book_activity($course, 'My Book', '<p>Intro</p>', 0);
        $this->assertGreaterThan(0, $created->bookid);
        $this->assertGreaterThan(0, $created->cmid);

        $book = $DB->get_record('book', ['id' => $created->bookid], '*', MUST_EXIST);
        $this->assertSame('My Book', $book->name);
        $originalrevision = $book->revision;

        $context = \context_module::instance($created->cmid);
        $chapterblocks = [
            ['title' => 'Chapter A', 'content' => '<p>A content</p>'],
            ['title' => 'Chapter B', 'content' => '<p>B content</p>'],
        ];
        $chapterids = book_importer::create_chapters($book, $context, $chapterblocks);

        $this->assertCount(2, $chapterids);

        $records = $DB->get_records('book_chapters', ['bookid' => $created->bookid], 'pagenum');
        $this->assertCount(2, $records);

        $first = array_shift($records);
        $this->assertSame('Chapter A', $first->title);
        $this->assertEquals(1, $first->pagenum);

        $second = array_shift($records);
        $this->assertSame('Chapter B', $second->title);
        $this->assertEquals(2, $second->pagenum);

        $updatedbook = $DB->get_record('book', ['id' => $created->bookid], '*', MUST_EXIST);
        $this->assertEquals($originalrevision + 1, $updatedbook->revision);
    }

    public function test_create_book_activity_sanitises_html_description(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        book_importer::create_book_activity(
            $course,
            'Safe book',
            '<p>Safe</p><script>alert(1)</script><img src="x" onerror="alert(2)">',
            0
        );

        $book = $DB->get_record('book', ['name' => 'Safe book'], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script', $book->intro);
        $this->assertStringNotContainsString('onerror', $book->intro);
    }

    public function test_create_chapters_sanitises_html_content(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $created = book_importer::create_book_activity($course, 'Safe book', '', 0);
        $book = $DB->get_record('book', ['id' => $created->bookid], '*', MUST_EXIST);
        $context = \context_module::instance($created->cmid);

        $chapterids = book_importer::create_chapters($book, $context, [[
            'title' => 'Safe chapter',
            'content' => '<p>Safe</p><script>alert(1)</script><img src="x" onerror="alert(2)">',
        ]]);

        $chapter = $DB->get_record('book_chapters', ['id' => reset($chapterids)], '*', MUST_EXIST);
        $this->assertStringNotContainsString('<script', $chapter->content);
        $this->assertStringNotContainsString('onerror', $chapter->content);
    }
}
