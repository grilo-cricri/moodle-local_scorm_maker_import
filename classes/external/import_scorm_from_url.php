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
 * Função externa para importar um pacote SCORM a partir de uma URL ou de um arquivo enviado.
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
 * Importa um pacote SCORM (de uma URL HTTPS autorizada ou de um arquivo enviado) para dentro de um curso.
 *
     * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_scorm_from_url extends external_api {
    /**
     * Descreve os parâmetros de import_scorm_from_url.
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
            'name' => new external_value(PARAM_TEXT, 'Name of the SCORM activity', VALUE_DEFAULT, 'SCORM importado'),
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
     * Baixa ou obtém (de uma URL ou de um arquivo de rascunho enviado) um pacote SCORM e cria a atividade a partir dele.
     *
     * @param int $courseid Id do curso onde a atividade SCORM será criada.
     * @param string $url URL HTTPS do pacote ZIP do SCORM em scormmaker.com.br. Obrigatório,
     *     a menos que $draftitemid seja informado.
     * @param string $name Nome da atividade SCORM.
     * @param int $sectionnum Número da seção do curso onde a atividade será adicionada.
     * @param int $draftitemid Id da área de rascunho contendo o ZIP do SCORM. Alternativa a $url.
     * @return array Array associativo com scormid, cmid e warnings.
     */
    public static function execute(
        int $courseid,
        string $url = '',
        string $name = 'SCORM importado',
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

        scorm_importer::require_scorm_module_available();

        $hasurl = $url !== '';
        $hasdraftitem = $draftitemid > 0;
        if ($hasurl === $hasdraftitem) {
            // Nenhum ou os dois foram informados — é obrigatório exatamente uma fonte.
            throw new moodle_exception('invalidscormsource', 'local_scorm_maker_import');
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
     * Descreve o retorno de import_scorm_from_url.
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
