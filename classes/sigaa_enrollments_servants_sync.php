<?php

// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 *
 * @package   local_sigaaintegration
 * @copyright 2024, Igor Ferreira Cemim
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 namespace local_sigaaintegration;

 use core\context;
 use core_course_category;
 use Exception;

class sigaa_enrollments_servants_sync extends sigaa_base_sync {
    private string $ano;

    private string $periodo;

    private array $courseNotFound = [];

    private int $studentroleid;

    private $course_discipline_mapper;

    private array $teachersNotFound = [];

    public function __construct(string $year, string $period)
    {
        parent::__construct();
        $this->ano = $year;
        $this->periodo = $period;
        $this->studentroleid = configuration::getIdPapelProfessor();
        $this->course_discipline_mapper = new course_discipline_mapper();
    }

    protected function get_records(campus $campus): array
    {
        $periodoletivo = sigaa_periodo_letivo::buildFromParameters($this->ano, $this->periodo);
        return $this->api_client->get_enrollments($campus, $periodoletivo);
    }

    protected function process_records(campus $campus, array $records): void
    {
        try {
            $this->enroll_teacher_into_courses($campus, $records);
        } catch (Exception $e) {
            mtrace(sprintf(
                'ERRO: Falha ao processar todas as inscrições do processor. erro: %s',
                $e->getMessage()
            ));
        }
        mtrace('INFO: Fim importação.');
    }

    /**
     * Tenta inscrever o professor nas disciplinas retornadas pela API do SIGAA.
     */
    private function enroll_teacher_into_courses($campus, array $enrollments): void
    {
        foreach ($enrollments as $enrollment) {
            foreach ($enrollment['disciplinas'] as $course_enrollment) {
                try {
                    if($this->validate($course_enrollment)) {
                        // generate_course_idnumber(campus $campus, $enrollment, $disciplina);
                        $course_discipline = $this->course_discipline_mapper->map_to_course_discipline($enrollment, $course_enrollment);
                        $courseidnumber = $course_discipline->generate_course_idnumber($campus);
                        foreach ($course_enrollment['docentes'] as $teacher)
                        {
                            // Converter o CPF para string
                            $cpf_docente_str = strval($teacher['cpf_docente']);
                            // Garantir que o CPF tenha 11 dígitos, completando com zeros à esquerda, se necessário
                            $cpf_docente_str = str_pad($cpf_docente_str, 11, "0", STR_PAD_LEFT);
                            if (in_array($cpf_docente_str, $this->teachersNotFound)) {
                                mtrace(sprintf('INFO: Usuário previamente registrado como não encontrado. usuário: %s', $cpf_docente_str));
                                continue;
                            }

                            // Buscar o usuário no banco
                            $user = $this->search_teacher($cpf_docente_str);
                            if (!$user) {
                                // Adicionar o CPF ao array de usuários não encontrados
                                $this->teachersNotFound[] = $cpf_docente_str;
                                mtrace(sprintf('ERRO: Usuário não encontrado. usuário: %s', $cpf_docente_str));
                            } else {
                                $this->enroll_teacher_into_single_course($user, $courseidnumber);
                            }
                        }
                    }
                } catch (Exception $e) {
                    mtrace(sprintf(
                        'ERRO: Falha ao processar inscrição de professor em uma disciplina. ' .
                        'matrícula: %s, usuário: %s, disciplina: %s, erro: %s',
                        $enrollment['matricula'],
                        $user->username,
                        $courseidnumber,
                        $e->getMessage()
                    ));
                }
            }
        }
    }

    /**
     * Busca professor pelo login/CPF.
     */
    private function search_teacher(string $login): object|false
    {
        global $DB;
        return $DB->get_record('user', ['username' => $login]);
    }

    private function validate(array $discipline): bool {
        // Valida os campos necessários da disciplina
        return isset($discipline['periodo']) &&
            isset($discipline['semestre_oferta_disciplina']) &&
            $discipline['semestre_oferta_disciplina'] !== null &&
            isset($discipline['turma']) &&
            $discipline['turma'] !== null;
    }

    /**
     * Busca disciplina pelo código de integração.
     */
    private function search_course(string $courseidnumber): ?object
    {
        /**
         * Evita busca repetida por disciplinas não encontradas.
         */
        if (array_search($courseidnumber, $this->courseNotFound)) {
            return null;
        }

        $results = core_course_category::search_courses(['search' => $courseidnumber]);
        if (count($results) > 0) {
            return current($results);
        }

        $this->courseNotFound[] = $courseidnumber;
        return null;
    }

    /**
     * Inscreve o professor em uma disciplina.
     */
    private function enroll_teacher(object $course, object $user): void
    {
        global $CFG;
        require_once($CFG->dirroot . '/lib/enrollib.php');

        if (is_enrolled(context\course::instance($course->id), $user)) {

            /*
            mtrace(sprintf(
                "INFO: O estudante já está inscrito na disciplina. usuário: %s, disciplina: %s",
                $user->username,
                $course->idnumber
            ));
            */
            return;
        }

        $enrolinstances = enrol_get_instances($course->id, true);
        $manualenrolinstance = current(array_filter($enrolinstances, function ($instance) {
            return $instance->enrol == 'manual';
        }));
        if (empty($manualenrolinstance)) {
            mtrace(
                'ERRO: o plugin Inscrição Manual ativado é um pré-requisito para o funcionamento da ' .
                'integração com o SIGAA. Ative o plugin Inscrição Manual e execute o processo de integração novamente.'
            );
            return;
        }

        $manualenrol = enrol_get_plugin('manual');
        $manualenrol->enrol_user($manualenrolinstance, $user->id, $this->studentroleid);
        mtrace(sprintf(
            "INFO: O docente foi inscrito na disciplina com sucesso. usuário: %s, disciplina: %s",
            $user->username,
            $course->idnumber
        ));
    }

    /**
     * Tenta increver o professor em uma determinada disciplina retornada pela API do SIGAA.
     */
    private function enroll_teacher_into_single_course(object $user, string $courseidnumber) :void
    {
        $course = $this->search_course($courseidnumber);
        if (!$course) {
            mtrace(sprintf(
                'ERRO: Disciplina não encontrada. Inscrição não realizada. usuário: %s, disciplina: %s',
                $user->username,
                $courseidnumber
            ));
            return;
        }

        $this->enroll_teacher($course, $user);
    }

}
