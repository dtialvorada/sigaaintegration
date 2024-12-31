<?php
namespace local_sigaaintegration;

use core\context;
use core_course_category;
use dml_exception;
use Exception;
use moodle_exception;

class sigaa_courses_sync extends sigaa_base_sync{

    private string $ano;

    private string $periodo;

    private sigaa_api_client $data_api;

    private course_moodle $course_moodle;

    public function __construct(string $year, string $period) {
        parent::__construct();
        $this->ano = $year;
        $this->periodo = $period;
        $this->data_api = sigaa_api_client::create();
        $this->course_moodle = new course_moodle();
    }

    protected function get_records($client_api, campus $campus): array
    {
        $periodoletivo = sigaa_periodo_letivo::buildFromParameters($this->ano, $this->periodo);
        $enrollments = $client_api->get_enrollments($campus, $periodoletivo);
        return $this->get_courses_to_create($campus, $enrollments);
    }

    protected function process_records(array $records, $campus): void
    {
        try {
            foreach ($records as $record){
                $this->course_moodle->create_course_for_discipline(
                    $record['disciplina'], $record['enrollment'], $record['category'], $record['course_idnumber']
                );
            }

        } catch (Exception $e) {
            mtrace(sprintf(
                'ERROR: Falha ao criar categorias, erro: %s',
                $e->getMessage()
            ));
        }

    }

    private function get_courses_to_create($campus, $enrollments): ?array {
        $courses_created = [];
        try {
            foreach ($enrollments as $enrollment) {
                foreach ($enrollment['disciplinas'] as $disciplina) {
                    if (isset($disciplina['periodo']) &&
                        isset($disciplina['semestre_oferta_disciplina']) &&
                        $disciplina['semestre_oferta_disciplina'] !== null &&
                        isset($disciplina['turma']) &&
                        $disciplina['turma'] !== null) {
                        $course_idnumber = $this->course_moodle->generate_course_idnumber($campus, $enrollment, $disciplina);
                        if (!array_key_exists($course_idnumber, $courses_created)) {
                            if (!$this->course_moodle->course_exists($course_idnumber)) {
                                $category_idnumber = $this->course_moodle->generate_category_level_three_id($campus, $enrollment['id_curso'], $disciplina);
                                $category = $this->course_moodle->get_category_for_discipline($category_idnumber);
                                if ($category) {
                                    //$this->create_course_for_discipline($disciplina, $enrollment, $category, $course_idnumber);
                                    $courses_created[$course_idnumber] = [
                                        'disciplina' => $disciplina,
                                        'enrollment' => $enrollment,
                                        'category' => $category->id,
                                        'course_idnumber' => $course_idnumber
                                    ];
                                    mtrace("add " . $course_idnumber );
                                    // $this->courses_created[] = $course_idnumber;
                                } else {
                                    mtrace("Categoria não cadastrada: " . $category_idnumber);
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $exception) {
            mtrace('ERRO: Falha ao importar disciplina. erro:' . $exception->getMessage());
        }
        return $courses_created;

    }



    /*
    private function buscar_professor_por_cpf(string $login): object|false {
        global $DB;
        return $DB->get_record('user', ['username' => $login]);
    }

    private function vincular_professores_disciplina(array $docentes, object $disciplina): void {
        $professorescadastrados = [];

        // Vincula o(s) professor(es)
        foreach ($docentes as $docente) {
            // Verifica se o CPF está vazio ou inválido
            if (empty($docente['cpf_docente'])) {
                mtrace(sprintf(
                    'ERRO: Professor sem CPF cadastrado no SIGAA. Não é possível inscrever na disciplina. Nome: %s',
                    $docente['docente']
                ));
                continue;
            }

            // Corrige o CPF para ter 11 dígitos, se necessário
            $cpf = $this->validar_e_corrigir_cpf($docente['cpf_docente']);

            if (!$cpf) {
                mtrace(sprintf(
                    'ERRO: CPF inválido para o professor: %s. Não foi possível inscrever na disciplina. Disciplina: %s',
                    $docente['docente'],
                    $disciplina->idnumber
                ));
                continue;
            }

            // Atualiza o CPF corrigido no docente
            $docente['cpf_docente'] = $cpf;

            // Busca o usuário pelo CPF
            $usuariodocente = $this->buscar_professor_por_cpf($cpf);
            if (!$usuariodocente) {
                mtrace(sprintf(
                    'ERRO: Professor não encontrado. Professor: %s, Disciplina: %s',
                    $cpf,
                    $disciplina->idnumber
                ));
                continue;
            }

            // Realiza inscrição
            $this->vincular_professor($disciplina, $usuariodocente);

            $professorescadastrados[] = $cpf;
        }
    }
    */

    /*
    private function validar_e_corrigir_cpf(string $cpf): ?string {
        // Remove qualquer caractere não numérico
        $cpf = preg_replace('/\D/', '', $cpf);

        // Verifica se o CPF tem 11 dígitos
        if (strlen($cpf) !== 11) {
            // Se o CPF não tiver 11 dígitos, corrige adicionando zeros à esquerda
            $cpf = str_pad($cpf, 11, '0', STR_PAD_LEFT);
        }

        // Verifica se o CPF é válido
        if ($this->validar_cpf($cpf)) {
            return $cpf;
        }
        // Se o CPF não for válido, retorna null
        return null;
    }

    private function validar_cpf(string $cpf): bool {
        // Exemplo de validação simples (aceita qualquer número de 11 dígitos)
        return preg_match('/^\d{11}$/', $cpf);
    }

    */

    /**
     * Inscreve o professor ao curso e vincula as roles necessárias no contexto do curso.
     *
     * @throws moodle_exception
     * @throws dml_exception

    private function vincular_professor(object $course, object $user): void {
        global $CFG;
        require_once($CFG->dirroot . '/lib/enrollib.php');

        if (is_enrolled(context\course::instance($course->id), $user)) {
            mtrace(sprintf(
                'INFO: Professor já está inscrito na disciplina. usuário: %s, disciplina: %s',
                $user->username,
                $course->idnumber
            ));
            return;
        }

        $enrolinstances = enrol_get_instances($course->id, true);
        $manualenrolinstance = current(array_filter($enrolinstances, function($instance) {
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
        $manualenrol->enrol_user($manualenrolinstance, $user->id, $this->editingteacherroleid);

        mtrace(sprintf(
            "INFO: Professor inscrito na disciplina com sucesso. professor: %s, disciplina: %s",
            $user->username,
            $course->idnumber
        ));
    }
    */

}
