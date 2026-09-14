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
 * Declarações de funções e serviço de web service do plugin local_scorm_maker_import.
 *
 * @package   local_scorm_maker_import
 * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_scorm_maker_import_import_scorm_from_url' => [
        'classname'        => 'local_scorm_maker_import\external\import_scorm_from_url',
        'methodname'       => 'execute',
        'description'      => 'Downloads a SCORM ZIP package from https://scormmaker.com.br and creates a SCORM activity from it.',
        'type'             => 'write',
        'ajax'             => false,
        'capabilities'     => 'moodle/course:manageactivities',
        'loginrequired'    => true,
        'readonlysession'  => false,
    ],
    'local_scorm_maker_import_import_book_from_html' => [
        'classname'        => 'local_scorm_maker_import\external\import_book_from_html',
        'methodname'       => 'execute',
        'description'      => 'Creates a Book activity from a block of HTML, one chapter per &lt;h1&gt; section.',
        'type'             => 'write',
        'ajax'             => false,
        'capabilities'     => 'moodle/course:manageactivities',
        'loginrequired'    => true,
        'readonlysession'  => false,
    ],
];

$services = [
    'Scorm Maker Import' => [
        'functions'       => [
            'local_scorm_maker_import_import_scorm_from_url',
            'local_scorm_maker_import_import_book_from_html',
        ],
        'restrictedusers' => 1,
        'enabled'         => 0,
        'shortname'       => 'local_scorm_maker_import',
        // Necessário para o fluxo de upload via draftitemid: o chamador envia o ZIP do SCORM
        // via /webservice/upload.php (o Moodle só permite isso para serviços com essa flag
        // habilitada) antes de passar o itemid resultante para import_scorm_from_url.
        'uploadfiles'     => 1,
    ],
];
