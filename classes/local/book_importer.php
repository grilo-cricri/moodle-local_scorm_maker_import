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
 * Book import logic for the local_scorm_maker_import plugin.
 *
 * @package   local_scorm_maker_import
 * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_scorm_maker_import\local;

use context_module;
use moodle_exception;
use stdClass;

/**
 * Creates a Book activity and splits a block of HTML into chapters at its <h1> tags.
 *
 * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class book_importer {
    /**
     * Checks that mod_book is installed and enabled on this site.
     *
     * @throws moodle_exception if mod_book is missing or disabled.
     */
    public static function require_book_module_available(): void {
        global $DB;

        $module = $DB->get_record('modules', ['name' => 'book']);
        if (!$module || empty($module->visible)) {
            throw new moodle_exception('nobookmodule', 'local_scorm_maker_import');
        }
    }

    /**
     * Creates a Book activity in the given course.
     *
     * @param stdClass $course Course record.
     * @param string $name Book name.
     * @param string $description Book introduction (HTML).
     * @param int $sectionnum Number of the section where the activity will be placed.
     * @return stdClass Object with ->bookid and ->cmid.
     */
    public static function create_book_activity(stdClass $course, string $name, string $description, int $sectionnum): stdClass {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/book/lib.php');

        [$module] = can_add_moduleinfo($course, 'book', $sectionnum);

        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'book';
        $moduleinfo->module = $module->id;
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $sectionnum;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->name = $name;
        $moduleinfo->intro = clean_text($description, FORMAT_HTML);
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->showdescription = 0;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->numbering = 0; // BOOK_NUM_NONE.
        $moduleinfo->customtitles = 0;

        $createdmoduleinfo = add_moduleinfo($moduleinfo, $course);

        $result = new stdClass();
        $result->bookid = (int) $createdmoduleinfo->instance;
        $result->cmid = (int) $createdmoduleinfo->coursemodule;

        return $result;
    }

    /**
     * Splits a block of HTML into chapters delimited by <h1> tags.
     *
     * Any content before the first <h1> becomes an "Introduction" chapter.
     * If no <h1> is found, the whole content becomes a single chapter.
     *
     * @param string $htmlcontent Full HTML content of the book.
     * @param string $defaulttitle Title for the single chapter used when no <h1> is found.
     * @return array List of ['title' => string, 'content' => string], in reading order.
     */
    public static function split_html_by_h1(string $htmlcontent, string $defaulttitle): array {
        $matches = [];
        $found = preg_match_all('/<h1\b[^>]*>(.*?)<\/h1>/is', $htmlcontent, $matches, PREG_OFFSET_CAPTURE);

        if (!$found) {
            return [
                [
                    'title' => $defaulttitle,
                    'content' => $htmlcontent,
                ],
            ];
        }

        $chapters = [];

        $firstheadingstart = $matches[0][0][1];
        $intro = trim(substr($htmlcontent, 0, $firstheadingstart));
        if ($intro !== '') {
            $chapters[] = [
                'title' => get_string('bookintro', 'local_scorm_maker_import'),
                'content' => $intro,
            ];
        }

        $headingcount = count($matches[0]);
        for ($i = 0; $i < $headingcount; $i++) {
            [$fullmatch, $headingstart] = $matches[0][$i];
            $title = trim(html_entity_decode(strip_tags($matches[1][$i][0]), ENT_QUOTES | ENT_HTML5));

            $contentstart = $headingstart + strlen($fullmatch);
            $contentend = ($i + 1 < $headingcount) ? $matches[0][$i + 1][1] : strlen($htmlcontent);
            $content = trim(substr($htmlcontent, $contentstart, $contentend - $contentstart));

            $chapters[] = [
                'title' => $title !== '' ? $title : $defaulttitle,
                'content' => $content,
            ];
        }

        return $chapters;
    }

    /**
     * Creates the book chapters described by split_html_by_h1(), in order.
     *
     * @param stdClass $book Book record (must have ->id).
     * @param context_module $context Module context of the book.
     * @param array $chapters List of ['title' => string, 'content' => string].
     * @return int[] Ids of the created chapters, in creation order.
     * @throws moodle_exception if any chapter cannot be created.
     */
    public static function create_chapters(stdClass $book, context_module $context, array $chapters): array {
        global $DB;

        $chapterids = [];
        $pagenum = 1;

        try {
            foreach ($chapters as $chapterdata) {
                $record = new stdClass();
                $record->bookid = $book->id;
                $record->pagenum = $pagenum++;
                $record->subchapter = 0;
                $record->title = $chapterdata['title'];
                $record->content = clean_text($chapterdata['content'], FORMAT_HTML);
                $record->contentformat = FORMAT_HTML;
                $record->hidden = 0;
                $record->importsrc = '';
                $record->timecreated = time();
                $record->timemodified = time();

                $record->id = $DB->insert_record('book_chapters', $record);
                $chapterids[] = (int) $record->id;

                \mod_book\event\chapter_created::create_from_chapter($book, $context, $record)->trigger();
            }
        } catch (\dml_exception $e) {
            throw new moodle_exception('chapterimporterror', 'local_scorm_maker_import', '', $e->getMessage());
        }

        $DB->set_field('book', 'revision', $book->revision + 1, ['id' => $book->id]);

        return $chapterids;
    }
}
