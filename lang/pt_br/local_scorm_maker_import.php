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
 * Brazilian Portuguese language strings for local_scorm_maker_import.
 *
 * @package   local_scorm_maker_import
 * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['bookintro'] = 'Introdução';
$string['chapterimporterror'] = 'Não foi possível criar um capítulo durante a importação do livro: {$a}.';
$string['invalidcourse'] = 'ID de curso inválido: {$a}.';
$string['invaliddraftfile'] = 'A área de rascunho informada deve conter exatamente um arquivo.';
$string['invalidscormsource'] = 'Informe exatamente um dos parâmetros "url" ou "draftitemid" (não ambos, nem nenhum).';
$string['invalidscormurl'] = 'A URL do pacote SCORM deve usar HTTPS e ter exatamente o host scormmaker.com.br.';
$string['invalidzip'] = 'O arquivo baixado não é um arquivo ZIP válido.';
$string['nobookmodule'] = 'O módulo de atividade Livro (mod_book) não está instalado ou está desabilitado neste site.';
$string['nomanifest'] = 'O arquivo ZIP não contém um arquivo imsmanifest.xml na raiz.';
$string['noscormmodule'] = 'O módulo de atividade SCORM (mod_scorm) não está instalado ou está desabilitado neste site.';
$string['packagetoolarge'] = 'O pacote é maior que o tamanho máximo de upload permitido neste curso ({$a}).';
$string['pluginname'] = 'Scorm Maker Import';
$string['privacy:metadata'] = 'O plugin Scorm Maker Import não armazena nenhum dado pessoal. Ele cria atividades SCORM e Livro em nome do usuário do web service que fez a chamada, mas os dados pertencentes a essas atividades são descritos pelos próprios provedores de privacidade desses módulos.';
$string['scormdownloaderror'] = 'Não foi possível baixar o pacote SCORM da URL informada: {$a}.';
