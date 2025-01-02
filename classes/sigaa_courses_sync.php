<?php
namespace local_sigaaintegration;

use core\context;
use core_user;
use dml_exception;
use Exception;

use moodle_exception;

class sigaa_courses_sync extends sigaa_base_sync{

    private string $year;
    private string $period;

    private $course_discipline_mapper;

    public function __construct(string $year, string $period) {
        parent::__construct();
        $this->year = $year;
        $this->period = $period;
        $this->course_discipline_mapper = new course_discipline_mapper();
    }

    protected function get_records(campus $campus): array
    {
        mtrace('INFO: Importando disciplinas...');
        $academic_period = sigaa_periodo_letivo::buildFromParameters($this->year, $this->period);
        $enrollments = $this->api_client->get_enrollments($campus, $academic_period);
        return $this->get_all_course_discipline($campus, $enrollments);
    }

    protected function process_records(campus $campus, array $records): void
    {
        try {
            foreach ($records as $course_discipline){
               $this->create_course_for_discipline($campus, $course_discipline);
            }
        } catch (Exception $e) {
            mtrace(sprintf(
                'ERROR: Falha ao criar categorias, erro: %s',
                $e->getMessage()
            ));
        }
    }

    private function create_course_for_discipline(campus $campus, course_discipline $course_discipline) {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        try {
            $class_group = str_replace(' ', '', $course_discipline->class_group);
            $period = $this->removerZeroNoPeriodo($course_discipline->period);
            $fullname = "{$course_discipline->discipline_name} / {$course_discipline->semester_offered}" . $this->get_year_or_semester_suffix($course_discipline->period) . " / {$period}";
            $shortname = "{$course_discipline->discipline_code} / {$course_discipline->course_id} / {$class_group} / {$course_discipline->semester_offered}" . $this->get_year_or_semester_suffix($course_discipline->period) . " / {$course_discipline->period}";


            $course_idnumber = $course_discipline->generate_course_idnumber($campus);
            if (!$this->course_exists($course_idnumber)) {
                $category_idnumber = $this->generate_category_level_three_id($campus, $course_discipline);
                $category = $this->get_category_for_discipline($category_idnumber);

                if ($category) {
                    $newCourse = (object)[
                        'fullname' => $fullname,
                        'shortname' => $shortname,
                        'category' => $category->id,
                        'idnumber' => $course_idnumber,
                        'summary' => $course_discipline->discipline_name,
                        'summaryformat' => FORMAT_PLAIN,
                        'format' => 'topics',
                        'visible' => 1,
                        'numsections' => 10,
                        'startdate' => time()
                    ];

                    $new_course = create_course($newCourse);

                    mtrace(sprintf(
                        'INFO: Disciplina criada. idnumber: %s, fullname: %s',
                        $new_course->idnumber,
                        $new_course->fullname
                    ));
                } else {
                    mtrace("ERROR: Falha ao importar disciplina. Categoria não cadastrada: " . $category_idnumber);
                }
            }
        } catch (Exception $exception) {
            mtrace('ERROR: Falha ao importar disciplina. erro:' . $exception->getMessage());
        }

    }

    private function get_all_course_discipline($campus, $enrollments): ?array {
        // Inicializando um array para armazenar objetos course_discipline únicos
        $disciplines = [];

        // Percorrendo os dados dos alunos
        foreach ($enrollments as $enrollment => $student) {
            // Percorrendo as disciplinas do aluno
            foreach ($student["disciplinas"] as $discipline) {

                // Valida a disciplina
                if ($this->validate($discipline)) {
                    // Mapeia os dados da disciplina para o objeto course_discipline
                    $discipline_obj = $this->course_discipline_mapper->map_to_course_discipline($student, $discipline);

                    // Filtra pelo idnumber da disciplina
                    $id = $discipline_obj->generate_course_idnumber($campus);
                    if (!isset($disciplines[$id])) {
                        $disciplines[$id] = $discipline_obj;
                    }
                } else {
                    //mtrace('A disciplina contém dados inválidos: ' . json_encode($discipline));
                }
            }
        }
        mtrace("INFO: Total de disciplinas únicas geradas para processamento: " . count($disciplines));
        return $disciplines;

    }

    private function validate(array $discipline): bool {
        // Valida os campos necessários da disciplina
        return isset($discipline['periodo']) &&
            isset($discipline['semestre_oferta_disciplina']) &&
            $discipline['semestre_oferta_disciplina'] !== null &&
            isset($discipline['turma']) &&
            $discipline['turma'] !== null;
    }

    private function get_year_or_semester_suffix($period) {
        return (substr($period, -1) === '0') ? 'º ano' : 'º semestre';
    }

    public function get_category_for_discipline($idnumber) {
        global $DB;
        return $DB->get_record('course_categories', ['idnumber' => $idnumber]);
    }

    public function course_exists($idnumber) {
        global $DB;
        return $DB->record_exists('course', ['idnumber' => $idnumber]);
    }

    private function removerZeroNoPeriodo($periodo) {
        // Verifica se o valor após a barra é 0
        if (substr($periodo, -2) === '/0') {
            // Remove a parte "/0" da string
            return substr($periodo, 0, -2);
        }
        // Caso não tenha "/0", retorna o valor original
        return $periodo;
    }

    private function generate_category_level_three_id(campus $campus, course_discipline $course_discipline) {
        return "{$campus->id_campus}.{$course_discipline->course_id}.{$course_discipline->period}.{$course_discipline->semester_offered}";
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
