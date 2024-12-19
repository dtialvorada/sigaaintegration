<?php
namespace local_sigaaintegration;

use core\context;
use core_course_category;
use dml_exception;
use Exception;
use moodle_exception;
use stdClass;


class sigaa_courses_sync {

    private string $ano;

    private string $periodo;

    private array $courses_created = [];

    private array $category_level_one_created = [];

    private array $category_level_two_created = [];

    private array $category_level_three_created = [];

    private int $basecategoryid;

    private int $editingteacherroleid;

    private array $clientlist = [];

    private sigaa_api_client $data_api;

    private sigaa_courses_manager $sigaa_courses;

    public function __construct(string $ano, string $periodo) {
        $this->ano = $ano;
        $this->periodo = $periodo;
        $this->editingteacherroleid = configuration::getIdPapelProfessor();
        $this->basecategoryid = configuration::getIdCategoriaBase();
        $this->clientlist = configuration::getClientListConfig();
        $this->data_api = sigaa_api_client::create();
    }

    public function sync(): void {
        mtrace('INFO: Importando disciplinas e categorias...');
        $this->sigaa_courses = new sigaa_courses_manager($this->data_api);
        $periodoletivo = sigaa_periodo_letivo::buildFromParameters($this->ano, $this->periodo);

        if($this->clientlist) {
            foreach ($this->clientlist as $campus) {
                mtrace("Campus ".$campus->description." - Início da Sincronização...");
                if ($campus->scheduled_sync) {
                    try {
                        $this->create_category_campus($campus);
                    } catch (Exception $e) {
                        mtrace(sprintf(
                            'ERROR: Falha ao criar categorias, erro: %s',
                            $e->getMessage()
                        ));
                    }

                    // Endpoint matriculados
                    $enrollments = $this->data_api->get_enrollments($campus, $periodoletivo);
                    foreach ($enrollments as $enrollment) {
                        $this->create_all_categories($campus, $enrollment);
                        $this->create_courses($campus, $enrollment);
                    }
                } else {
                    mtrace("Sincronização desativada para o campus: " . $campus->description);
                }
                //mtrace("Campus ". $campus->description. " - Sincronizado!");
            }
        }
    }

    private function create_courses($campus, $enrollment) {
        try {
            foreach ($enrollment['disciplinas'] as $disciplina) {
                $course_idnumber = $this->generate_course_idnumber($campus, $enrollment, $disciplina);
                if (in_array($course_idnumber, $this->courses_created)) {
                    return;
                }
                if (!$this->course_exists($course_idnumber)) {
                    $category = $this->get_category_for_discipline($campus, $disciplina, $enrollment);
                    if ($category) {
                        $this->create_course_for_discipline($disciplina, $enrollment, $category, $course_idnumber);
                    }
                }
                $this->courses_created[] = $course_idnumber;
            }
        } catch (Exception $exception) {
            mtrace('ERRO: Falha ao importar disciplina. erro:' . $exception->getMessage());
        }
    }

    private function create_course_for_discipline($disciplina, $student, $category, $idnumber) {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $turma = str_replace(' ', '', $disciplina['turma']);
        $periodo = $this->removerZeroNoPeriodo($disciplina['periodo']);
        $fullname = "{$disciplina['disciplina']} / {$disciplina['semestre_oferta_disciplina']}" . $this->get_year_or_semester_suffix($disciplina['periodo']) . " / {$periodo}";
        $shortname = "{$disciplina['cod_disciplina']} / {$student['id_curso']} / {$turma} / {$disciplina['semestre_oferta_disciplina']}" . $this->get_year_or_semester_suffix($disciplina['periodo']) . " / {$disciplina['periodo']}";

        $newCourse = (object)[
            'fullname' => $fullname,
            'shortname' => $shortname,
            'category' => $category->id,
            'idnumber' => $idnumber,
            'summary' => $disciplina['disciplina'],
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
    }

    private function get_category_for_discipline($campus, $disciplina, $enrollment) {
        global $DB;
        $idnumber = $this->generate_category_level_three_id($campus, $enrollment['id_curso'], $disciplina);
        return $DB->get_record('course_categories', ['idnumber' => $idnumber]);
    }

    private function course_exists($idnumber) {
        global $DB;
        return $DB->record_exists('course', ['idnumber' => $idnumber]);
    }

    private function create_category_campus(campus $campus): void {
        if (!$this->category_exists($campus->id_campus)) {
            $course = $this->sigaa_courses->get_courses_by_campus($campus);
            $this->create_category($course['campus_descricao'], $campus->id_campus, $this->basecategoryid);
        }
    }

    private function create_all_categories($campus, $enrollment) {
        if ($enrollment['status'] == 'ATIVO') {
            // Evita recriação de nível 1
            if (!in_array($enrollment['id_curso'], $this->category_level_one_created)) {
                $this->create_category_level_one($campus, $enrollment);
                $this->category_level_one_created[] = $enrollment['id_curso'];
            }
            // Loop único nas disciplinas para criar níveis 2 e 3
            $uniquePeriods = [];
            foreach ($enrollment['disciplinas'] as $disciplina) {
                // Determina ID e verifica unicidade do nível 2
                $idnumber_level_two = "{$campus->id_campus}.{$enrollment['id_curso']}.{$disciplina['periodo']}";

                if (!in_array($idnumber_level_two, $this->category_level_two_created)) {
                    $this->create_category_level_two($campus, $enrollment, $disciplina, $idnumber_level_two);
                    $this->category_level_two_created[] = $idnumber_level_two;
                }

                $idnumber_level_three = $this->generate_category_level_three_id($campus, $enrollment['id_curso'], $disciplina);
                if (!in_array($idnumber_level_three, $this->category_level_three_created)) {
                    $this->create_category_level_three($campus, $enrollment, $disciplina);
                    $this->category_level_three_created[] = $idnumber_level_three;
                }

            }
        }


    }

    private function create_category_level_one(campus $campus, $enrollment){
        global $DB;

        $parentCategory = $DB->get_record('course_categories', ['idnumber' => $campus->id_campus]);
        if (!$parentCategory) {
            throw new Exception("Categoria pai não encontrada: [" . $campus->id_campus . "] Campus ". $campus->description);
        }
        $name = $enrollment['curso'];
        $idnumber =  "{$campus->id_campus}.{$enrollment['id_curso']}";

        $course = $this->sigaa_courses->get_courses_by_id_course($campus, $enrollment['id_curso']);
        if(isset($course)) {

            if($course['modalidade_educacao'] == $campus::MODALIDADES[$campus->modalidade_educacao]) {

                if (!$this->category_exists($idnumber)) {
                    $this->create_category($name, $idnumber, $parentCategory->id);
                }
            }

        }
    }

    private function create_category_level_two($campus, $enrollment, $disciplina, $idnumber_level_two) {
        global $DB;

        $parentCategory = $DB->get_record('course_categories', ['idnumber' => "{$campus->id_campus}.{$enrollment['id_curso']}"]);
        if ($parentCategory && !$this->category_exists($idnumber_level_two)) {
            $periodo = $this->removerZeroNoPeriodo($disciplina['periodo']);
            $this->create_category($periodo, $idnumber_level_two, $parentCategory->id);
        }

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

    private function create_category_level_three(campus $campus, $enrollment, $disciplina) {
        global $DB;

        // algumas vezes o semestre_oferta_disciplina está vazio
        if (isset($disciplina['periodo']) && isset($disciplina['semestre_oferta_disciplina'])) {
            $idnumber_parent = "{$campus->id_campus}.{$enrollment['id_curso']}.{$disciplina['periodo']}";
            $parentCategory = $DB->get_record('course_categories', ['idnumber' => $idnumber_parent]);

            if ($parentCategory) {
                // Gera o identificador do nível 3
                $idnumber = $this->generate_category_level_three_id($campus, $enrollment['id_curso'], $disciplina);

                if (substr($disciplina['periodo'], -2) === '/0' && $disciplina['semestre_oferta_disciplina'] === '0') {
                    $name = "Optativas";
                } else {
                    $name = "{$disciplina['semestre_oferta_disciplina']}" . $this->get_year_or_semester_suffix($disciplina['periodo']);
                }
                if (!$this->category_exists($idnumber)) {
                    $this->create_category($name, $idnumber, $parentCategory->id);
                }
            }
        }
    }

    private function generate_category_level_three_id(campus $campus, $id_curso, $disciplina) {
        return "{$campus->id_campus}.{$id_curso}.{$disciplina['periodo']}.{$disciplina['semestre_oferta_disciplina']}";
    }

    private function get_year_or_semester_suffix($period) {
        return (substr($period, -1) === '0') ? 'º ano' : 'º semestre';
    }

    private function category_exists($idnumber) {
        global $DB;
        return $DB->record_exists('course_categories', ['idnumber' => $idnumber]);
    }

    private function create_category($name, $idnumber, $parentId) {
        global $DB;

        $categoriacurso = $DB->get_record('course_categories', ['idnumber' => $idnumber]);
        if (!$categoriacurso) {
            $category = new stdClass();
            $category->name = string_helper::capitalize($name);
            $category->idnumber = $idnumber;
            $category->parent = $parentId;

            $categoriacurso = core_course_category::create($category);

            mtrace(sprintf(
                'INFO: Categoria criada. idnumber: %s, Nome: %s',
                $categoriacurso->idnumber,
                $category->name
            ));
        }
    }

    private function generate_course_idnumber(campus $campus, $enrollment, $disciplina) {
        $turma = str_replace(' ', '', $disciplina['turma']);
        $myid =  "{$campus->id_campus}.{$enrollment['id_curso']}.{$turma}.{$disciplina['id_disciplina']}.{$disciplina['periodo']}.{$disciplina['semestre_oferta_disciplina']}";
        return $myid;
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
