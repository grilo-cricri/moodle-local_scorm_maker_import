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
 * Lógica de importação de pacotes SCORM do plugin local_scorm_maker_import.
 *
 * @package   local_scorm_maker_import
 * @copyright 2024 ScormMaker.com.br
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_scorm_maker_import\local;

use moodle_exception;
use stdClass;

/**
 * Baixa um pacote SCORM (a partir de uma URL ou de uma área de rascunho) e cria a atividade a partir dele.
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

    /**
     * Verifica se o mod_scorm está instalado e habilitado neste site.
     *
     * @throws moodle_exception se o mod_scorm estiver ausente ou desabilitado.
     */
    public static function require_scorm_module_available(): void {
        global $DB;

        $module = $DB->get_record('modules', ['name' => 'scorm']);
        if (!$module || empty($module->visible)) {
            throw new moodle_exception('noscormmodule', 'local_scorm_maker_import');
        }
    }

    /**
     * Garante que o diretório temporário de trabalho do plugin exista e retorna seu caminho.
     *
     * @param string $erroronfailure Código de erro do arquivo de idioma deste plugin a lançar
     *     se o diretório não puder ser criado.
     * @return string Caminho absoluto do diretório temporário.
     * @throws moodle_exception se o diretório não puder ser criado.
     */
    protected static function ensure_temp_dir(string $erroronfailure): string {
        global $CFG;

        $tempsubdir = $CFG->tempdir . '/scorm_maker_import';
        if (!is_dir($tempsubdir) && !mkdir($tempsubdir, $CFG->directorypermissions, true) && !is_dir($tempsubdir)) {
            throw new moodle_exception($erroronfailure, 'local_scorm_maker_import');
        }

        return $tempsubdir;
    }

    /**
     * Verifica se a URL remota pertence exatamente à origem autorizada.
     *
     * A comparação do host é exata de propósito: subdomínios, hosts com sufixo
     * parecido, credenciais embutidas e portas alternativas não são permitidos.
     *
     * @param string $url URL a validar.
     * @throws moodle_exception se a URL não usar a origem autorizada.
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
     * Baixa um arquivo ZIP remoto para um arquivo único dentro de $CFG->tempdir.
     *
     * @param string $url URL HTTPS do pacote ZIP no host autorizado.
     * @return string Caminho absoluto do arquivo temporário baixado.
     * @throws moodle_exception se o download falhar por qualquer motivo.
     */
    public static function download_to_temp(string $url): string {
        global $CFG;

        self::validate_download_url($url);

        $tempsubdir = self::ensure_temp_dir('scormdownloaderror');
        $tempfile = $tempsubdir . '/' . uniqid('scorm_', true) . '.zip';

        $filehandle = fopen($tempfile, 'wb');
        if ($filehandle === false) {
            self::delete_temp_file($tempfile);
            throw new moodle_exception('scormdownloaderror', 'local_scorm_maker_import', '', $url);
        }

        $curl = new \curl();
        try {
            // Redirecionamentos ficam desabilitados.
            // Isso impede que a origem autorizada encaminhe o servidor Moodle para outro host.
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
            ]);
        } finally {
            fclose($filehandle);
        }

        @chmod($tempfile, $CFG->filepermissions);

        $info = $curl->get_info();
        $status = is_array($info) ? (int) ($info['http_code'] ?? 0) : 0;

        if ($curl->get_errno() !== 0 || $status !== 200 || !is_readable($tempfile)) {
            self::delete_temp_file($tempfile);
            throw new moodle_exception('scormdownloaderror', 'local_scorm_maker_import', '', $url);
        }

        return $tempfile;
    }

    /**
     * Copia o único arquivo encontrado na área de rascunho de um usuário para um arquivo único dentro de $CFG->tempdir.
     *
     * Usado como alternativa a download_to_temp() quando o chamador enviou o ZIP do SCORM diretamente via
     * /webservice/upload.php, em vez de apontar para uma URL HTTPS autorizada. Áreas de rascunho sempre pertencem ao
     * contexto do próprio usuário atual ($USER), portanto isso não pode ser usado para acessar arquivos de
     * outro usuário.
     *
     * @param int $draftitemid Id da área de rascunho (item id) retornado por /webservice/upload.php.
     * @return string Caminho absoluto do arquivo temporário copiado.
     * @throws moodle_exception se a área de rascunho estiver vazia ou contiver mais de um arquivo.
     */
    public static function stage_file_from_draft(int $draftitemid): string {
        global $USER;

        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);

        if (count($files) !== 1) {
            throw new moodle_exception('invaliddraftfile', 'local_scorm_maker_import');
        }

        $tempsubdir = self::ensure_temp_dir('invaliddraftfile');
        $tempfile = $tempsubdir . '/' . uniqid('scorm_', true) . '.zip';
        reset($files)->copy_content_to($tempfile);

        return $tempfile;
    }

    /**
     * Confirma que o ZIP no caminho informado contém o arquivo imsmanifest.xml na raiz.
     *
     * @param string $zippath Caminho absoluto do arquivo ZIP baixado.
     * @throws moodle_exception se o arquivo não for um ZIP válido ou não tiver o manifesto na raiz.
     */
    public static function validate_manifest(string $zippath): void {
        $zip = new \ZipArchive();
        $result = $zip->open($zippath);
        if ($result !== true) {
            throw new moodle_exception('invalidzip', 'local_scorm_maker_import');
        }

        // FL_NODIR é omitido de propósito: o manifesto precisa estar na raiz do arquivo, não em uma subpasta.
        $hasmanifest = $zip->locateName('imsmanifest.xml', \ZipArchive::FL_NOCASE) !== false;
        $zip->close();

        if (!$hasmanifest) {
            throw new moodle_exception('nomanifest', 'local_scorm_maker_import');
        }
    }

    /**
     * Apaga o arquivo temporário baixado, ignorando arquivos inexistentes.
     *
     * @param string $path Caminho absoluto do arquivo temporário.
     */
    public static function delete_temp_file(string $path): void {
        if ($path !== '' && file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Cria uma atividade SCORM no curso informado a partir de um arquivo ZIP local.
     *
     * @param stdClass $course Registro do curso.
     * @param string $zippath Caminho absoluto do arquivo ZIP já validado.
     * @param string $name Nome da atividade.
     * @param int $sectionnum Número da seção onde a atividade será colocada.
     * @return stdClass Objeto com ->scormid e ->cmid.
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
