<?php
namespace local_sigaaintegration;

use core\context;
use core_course_category;
use dml_exception;
use Exception;
use moodle_exception;
use stdClass;

class sigaa_categories_sync extends sigaa_base_sync{

    private string $year;
    private string $period;
    private int $basecategoryid;
    private array $category_level_one_created = [];
    private array $category_level_two_created = [];
    private array $category_level_three_created = [];
    private sigaa_courses_manager $sigaa_courses_manager;


    public function __construct(string $year, string $period) {
        parent::__construct();
        $this->year = $year;
        $this->period = $period;
        $this->basecategoryid = configuration::getIdCategoriaBase();
        $this->sigaa_courses_manager = new sigaa_courses_manager($this->api_client);
    }

    protected function get_records(campus $campus): array
    {
        mtrace('INFO: Importando categorias...');
        $academic_period = sigaa_academic_period::buildFromParameters($this->year, $this->period);
        $enrollments = $this->api_client->get_enrollments($campus, $academic_period);
        return $this->get_all_course_discipline($campus, $enrollments);
    }

    protected function process_records(campus $campus, array $records): void
    {
        try {
            // Em cada processamento é verificado, uma vez, a criação da categoria com o nome do campus
            if (!$this->category_exists($campus->id_campus)) {
                $courses = $this->sigaa_courses_manager->get_courses_by_campus($campus);
                $this->create_category($courses['campus_descricao'], $campus->id_campus, $this->basecategoryid);
            }

            foreach ($records as $course_discipline) {

                $course = $this->sigaa_courses_manager->get_courses_by_id_course($campus, $course_discipline->course_id);

                if(isset($course['modalidade_educacao'])) {
                    if ($course['modalidade_educacao'] == $campus::MODALIDADES[$campus->modalidade_educacao]) {
                        // Adiciona o índice no array de controle,
                        // Adiciona true para operar junto com a função isset

                        // Criação do nível 1 (curso)
                        $idnumber_level_one = $this->generate_category_level_two_id($campus, $course_discipline);
                        if (!isset($this->category_level_one_created[$idnumber_level_one])) {
                            $this->create_category_level_one($campus, $course_discipline);
                            $this->category_level_one_created[$idnumber_level_one] = true;
                        }

                        // Criação do nível 2 (período)
                        $idnumber_level_two = $this->generate_category_level_two_id($campus, $course_discipline);
                        if (!isset($this->category_level_two_created[$idnumber_level_two])) {
                            $this->create_category_level_two($campus, $course_discipline);
                            $this->category_level_two_created[$idnumber_level_two] = true;
                        }

                        // Criação do nível 3 (semestre ou ano)
                        $idnumber_level_three = $this->generate_category_level_three_id($campus, $course_discipline);
                        if (!isset($this->category_level_three_created[$idnumber_level_three])) {
                            $this->create_category_level_three($campus, $course_discipline);
                            $this->category_level_three_created[$idnumber_level_three] = true;
                        }

                    }
                }
            }
        } catch (Exception $e) {
            mtrace(sprintf('ERROR: Falha ao processar registros, erro: %s', $e->getMessage()));
        }
    }

    private function get_all_course_discipline($campus, $enrollments): ?array {
        // Inicializando um array para armazenar objetos course_discipline únicos
        $disciplines = [];

        // Percorrendo os dados dos alunos
        foreach ($enrollments as $enrollment => $student) {
            // Percorrendo as disciplinas do aluno
            foreach ($student["disciplinas"] as $discipline) {
                // Mapeia os dados da disciplina para o objeto course_discipline
                $discipline_obj = $this->map_to_course_discipline($student, $discipline);

                $id = $this->generate_category_level_three_id($campus, $discipline_obj);
                if (!isset($disciplines[$id])) {
                    $disciplines[$id] = $discipline_obj;
                }
            }
        }
        mtrace(count($disciplines));
        return $disciplines;

    }

    private function generate_category_level_one_id(campus $campus, course_discipline $course_discipline ) {
        return "{$campus->id_campus}.{$course_discipline->course_id}";
    }
    private function create_category_level_one(campus $campus, $course_discipline) {
        global $DB;

        // Obtém a categoria pai para o campus
        $parent_category = $DB->get_record('course_categories', ['idnumber' => $campus->id_campus]);
        if (!$parent_category) {
            throw new Exception("Categoria pai não encontrada para o campus: " . $campus->description);
        }

        // Nome do curso
        $name = $course_discipline->course_name;
        // ID da categoria
        $idnumber = $this->generate_category_level_one_id($campus, $course_discipline);

        // Verifica se a categoria já existe antes de criar
        if (!$this->category_exists($idnumber)) {
            $this->create_category($name, $idnumber, $parent_category->id);
        }
    }
    private function generate_category_level_two_id(campus $campus, course_discipline $course_discipline ) {
        return "{$campus->id_campus}.{$course_discipline->course_id}.{$course_discipline->period}";
    }
    private function create_category_level_two(campus $campus, course_discipline $course_discipline) {
        global $DB;

        $parent_idnumber = $this->generate_category_level_one_id($campus, $course_discipline);
        $parent_category = $DB->get_record('course_categories', ['idnumber' => $parent_idnumber]);

        $idnumber_level_two = $this->generate_category_level_two_id($campus, $course_discipline);
        if ($parent_category && !$this->category_exists($idnumber_level_two)) {
            $period = $this->remove_zero_from_period($course_discipline->period);
            $this->create_category($period, $idnumber_level_two, $parent_category->id);
        }
    }

    private function generate_category_level_three_id(campus $campus, course_discipline $course_discipline ) {
        return "{$campus->id_campus}.{$course_discipline->course_id}.{$course_discipline->period}.{$course_discipline->semester_offered}";
    }

    private function create_category_level_three(campus $campus, course_discipline $course_discipline) {
        global $DB;

        // algumas vezes o semestre_oferta_disciplina está vazio
        if (isset($course_discipline->period) && isset($course_discipline->semester_offered)) {
            $parent_idnumber = $this->generate_category_level_two_id($campus, $course_discipline);
            $parent_category = $DB->get_record('course_categories', ['idnumber' => $parent_idnumber]);

            if ($parent_category) {
                // Gera o identificador do nível 3
                $idnumber = $this->generate_category_level_three_id($campus, $course_discipline);

                if (substr($course_discipline->period, -2) !== '/0' && $course_discipline->semester_offered === '0') {
                    $name = "Optativas";
                } else {
                    $name = "{$course_discipline->semester_offered}" . $this->get_year_or_semester_suffix($course_discipline->period);
                }
                if (!$this->category_exists($idnumber)) {
                    $this->create_category($name, $idnumber, $parent_category->id);

                }
            }
        }
    }

    private function create_category($name, $idnumber, $parent_id) {
        global $DB;

        $course_category_record = $DB->get_record('course_categories', ['idnumber' => $idnumber]);
        if (!$course_category_record) {
            $category = new stdClass();
            $category->name = string_helper::capitalize($name);
            $category->idnumber = $idnumber;
            $category->parent = $parent_id;

            $course_category_record = core_course_category::create($category);

            mtrace(sprintf(
                'INFO: Categoria criada. idnumber: %s, Nome: %s',
                $course_category_record->idnumber,
                $category->name
            ));
        }
    }


    private function category_exists($idnumber) {
        global $DB;
        return $DB->record_exists('course_categories', ['idnumber' => $idnumber]);
    }

    private function remove_zero_from_period($period) {
        // Verifica se o valor após a barra é 0
        if (substr($period, -2) === '/0') {
            // Remove a parte "/0" da string
            return substr($period, 0, -2);
        }
        // Caso não tenha "/0", retorna o valor original
        return $period;
    }

    private function get_year_or_semester_suffix($period) {
        return (substr($period, -1) === '0') ? 'º ano' : 'º semestre';
    }

    private function map_to_course_discipline($student, $discipline): course_discipline {
        $course_data = [
            "course_id" => $student["id_curso"],
            "course_code" => $student["cod_curso"],
            "course_name" => $student["curso"],
            "course_level" => $student["curso_nivel"],
            "status" => $student["status"]
        ];

        return new course_discipline(
            $course_data["course_id"],
            $course_data["course_code"],
            $course_data["course_name"],
            $course_data["course_level"],
            $course_data["status"],
            $discipline["disciplina"],
            $discipline["cod_disciplina"],
            $discipline["id_disciplina"],
            $discipline["semestre_oferta_disciplina"],
            $discipline["periodo"],
            $discipline["situacao_matricula"],
            $discipline["turma"],
            $discipline["modalidade_educacao_turma"],
            $discipline["turno_turma"]
        );
    }

}
