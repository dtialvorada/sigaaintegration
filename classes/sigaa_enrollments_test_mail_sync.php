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

class sigaa_enrollments_test_mail_sync {
    private string $cpf;

    private string $courseidnumber;

    private string $userroleid;

    public function __construct(string $cpf, string $courseidnumber)
    {
        $this->cpf = $cpf;
        $this->courseidnumber = $courseidnumber;
        $this->userroleid = configuration::getIdPapelProfessor();
        mtrace("Entrei no construtor");
    }

    public function sync(): void
    {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $this->enroll_teacher_into_courses();
            $transaction->allow_commit();
        } catch (Exception $e) {
            $transaction->rollback($e);
            mtrace('ERROR: Transação falhou. ' . $e->getMessage());
        }

        gc_collect_cycles(); // Liberar memória após cada lote

    }

    private function enroll_teacher_into_courses(): void
    {
        try {

            // Buscar o usuário no banco
            $user = $this->search_teacher($this->cpf);
            if (!$user) {
                mtrace(sprintf('ERRO: Usuário não encontrado. usuário: %s', $this->cpf));
            } else {
                mtrace(sprintf('INFO: Usuário encontrado. usuário: %s', $this->cpf));
                $this->enroll_teacher_into_single_course($user, $this->courseidnumber);
            }


        } catch (Exception $e) {
            mtrace(sprintf(
                'ERRO: Falha ao processar inscrição de professor em uma disciplina. ' .
                'cpf: %s, disciplina: %s, erro: %s',
                $this->cpf,
                $this->courseidnumber,
                $e->getMessage()
            ));
        }

    }

    /**
     * Busca professor pelo login/CPF.
     */
    private function search_teacher(string $cpf): object|false
    {
        global $DB;
        return $DB->get_record('user', ['username' => $cpf]);
    }


    /**
     * Busca disciplina pelo código de integração.
     */
    private function search_course(string $courseidnumber): ?object
    {


        $results = core_course_category::search_courses(['search' => $courseidnumber]);
        if (count($results) > 0) {
            return current($results);
        }

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


            mtrace(sprintf(
                "INFO: O estudante já está inscrito na disciplina. usuário: %s, disciplina: %s",
                $user->username,
                $course->idnumber
            ));

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
        $manualenrol->enrol_user($manualenrolinstance, $user->id, $this->userroleid);
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
        mtrace(sprintf(
            'INFO: Disciplina encontrada. Inscrição realizada. usuário: %s, disciplina: %s',
            $user->username,
            $courseidnumber
        ));

        $this->enroll_teacher($course, $user);
    }

}
