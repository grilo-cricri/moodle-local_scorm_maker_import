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
 * Função externa para importar uma atividade Livro a partir de um bloco de HTML.
 *
 * @package   local_scorm_maker_import
 * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_scorm_maker_import\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_scorm_maker_import\local\book_importer;
use moodle_exception;

/**
 * Cria uma atividade Livro a partir de um bloco de HTML, um capítulo por seção <h1>.
 *
     * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_book_from_html extends external_api {
    /**
     * Descreve os parâmetros de import_book_from_html.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Id of the course to add the Book activity to'),
            'htmlcontent' => new external_value(PARAM_RAW, 'Full HTML content of the book'),
            'name' => new external_value(
                PARAM_TEXT,
                'Title of the book; empty uses the defaultbookname language string',
                VALUE_DEFAULT,
                ''
            ),
            'description' => new external_value(PARAM_RAW, 'Book description/introduction HTML', VALUE_DEFAULT, ''),
            'sectionnum' => new external_value(PARAM_INT, 'Course section number to add the activity to', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Cria uma atividade Livro e divide htmlcontent em capítulos pelas tags <h1>.
     *
     * @param int $courseid Id do curso onde a atividade Livro será criada.
     * @param string $htmlcontent Conteúdo HTML completo do livro.
     * @param string $name Título do livro.
     * @param string $description Descrição/introdução do livro em HTML.
     * @param int $sectionnum Número da seção do curso onde a atividade será adicionada.
     * @return array Array associativo com bookid, cmid e chapterids.
     */
    public static function execute(
        int $courseid,
        string $htmlcontent,
        string $name = '',
        string $description = '',
        int $sectionnum = 0
    ): array {
        global $DB;

        [
            'courseid' => $courseid,
            'htmlcontent' => $htmlcontent,
            'name' => $name,
            'description' => $description,
            'sectionnum' => $sectionnum,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'htmlcontent' => $htmlcontent,
            'name' => $name,
            'description' => $description,
            'sectionnum' => $sectionnum,
        ]);

        // O método context_course::instance() por si só já exige que o curso exista (padrão
        // MUST_EXIST), então um courseid inválido falha aqui de forma idêntica para
        // qualquer chamador, antes mesmo de require_capability() rodar. Isso garante
        // que a distinção invalidcourse/sem-permissão nunca possa ser usada para
        // enumerar quais ids de curso existem no site.
        try {
            $context = \context_course::instance($courseid);
            require_capability('moodle/course:manageactivities', $context);
            self::validate_context($context);
            $course = get_course($courseid);
        } catch (\dml_exception) {
            throw new moodle_exception('invalidcourse', 'local_scorm_maker_import', '', $courseid);
        }

        book_importer::require_book_module_available();

        if ($name === '') {
            $name = get_string('defaultbookname', 'local_scorm_maker_import');
        }


        $created = book_importer::create_book_activity($course, $name, $description, $sectionnum);

        $book = $DB->get_record('book', ['id' => $created->bookid], '*', MUST_EXIST);
        $modulecontext = \context_module::instance($created->cmid);

        $chapterblocks = book_importer::split_html_by_h1($htmlcontent, $name);
        $chapterids = book_importer::create_chapters($book, $modulecontext, $chapterblocks);

        return [
            'bookid' => $created->bookid,
            'cmid' => $created->cmid,
            'chapterids' => $chapterids,
        ];
    }

    /**
     * Descreve o retorno de import_book_from_html.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'bookid' => new external_value(PARAM_INT, 'Id of the created book instance'),
            'cmid' => new external_value(PARAM_INT, 'Id of the created course module'),
            'chapterids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Chapter id'),
                'Ids of the created chapters, in creation order'
            ),
        ]);
    }
}
