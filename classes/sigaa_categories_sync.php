<?php
namespace local_sigaaintegration;

use core\context;
use core_course_category;
use dml_exception;
use Exception;
use moodle_exception;
use stdClass;

class sigaa_categories_sync extends sigaa_base_sync{

    private string $yaar;
    private string $period;
    private int $basecategoryid;
    private array $category_level_one_created = [];
    private array $category_level_two_created = [];
    private array $category_level_three_created = [];
    private sigaa_courses_manager $sigaa_courses_manager;


    public function __construct(string $year, string $period) {
        parent::__construct();
        $this->yaar = $year;
        $this->period = $period;
        $this->basecategoryid = configuration::getIdCategoriaBase();
        $this->sigaa_courses_manager = new sigaa_courses_manager($this->api_client);
    }

    protected function get_records(campus $campus): array
    {
        mtrace('INFO: Importando disciplinas e categorias...');
        $periodoletivo = sigaa_periodo_letivo::buildFromParameters($this->yaar, $this->period);
        $enrollments = $this->api_client->get_enrollments($campus, $periodoletivo);
        return $this->get_all_course_discipline($campus, $enrollments);
    }

    protected function process_records(array $records, $campus): void
    {
        try {
            // Em cada processamento é verificado a criação da categoria com o nome do campus
            if (!$this->category_exists($campus->id_campus)) {
                $courses = $this->sigaa_courses_manager->get_courses_by_campus($campus);
                $this->create_category($courses['campus_descricao'], $campus->id_campus, $this->basecategoryid);
            }

            // TODO: Alterar a logica de in_array para isset
            foreach ($records as $course_discipline) {
                // Criação do nível 1 (curso)
                if (!in_array($course_discipline->course_id, $this->category_level_one_created)) {
                    $this->create_category_level_one($campus, $course_discipline);
                    $this->category_level_one_created[] = $course_discipline->course_id;
                }
                // Criação do nível 2 (período)
                $idnumber_level_two = "{$campus->id_campus}.{$course_discipline->course_id}.{$course_discipline->period}";
                if (!in_array($idnumber_level_two, $this->category_level_two_created)) {
                    $this->create_category_level_two($campus, $course_discipline, $idnumber_level_two);
                    $this->category_level_two_created[] = $idnumber_level_two;
                }

                // Criação do nível 3 (semestre ou ano)
                $idnumber_level_three = $this->generate_category_level_three_id($campus, $course_discipline);
                if (!in_array($idnumber_level_three, $this->category_level_three_created)) {
                    $this->create_category_level_three($campus, $course_discipline);
                    $this->category_level_three_created[] = $idnumber_level_three;
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

    private function create_category_level_one(campus $campus, $course_discipline) {
        global $DB;

        // Obtém a categoria pai para o campus
        $parentCategory = $DB->get_record('course_categories', ['idnumber' => $campus->id_campus]);
        if (!$parentCategory) {
            throw new Exception("Categoria pai não encontrada para o campus: " . $campus->description);
        }

        $name = $course_discipline->course_name;  // Nome do curso
        $idnumber = "{$campus->id_campus}.{$course_discipline->course_id}"; // ID da categoria

        // Verifica se a categoria já existe antes de criar
        if (!$this->category_exists($idnumber)) {
            $this->create_category($name, $idnumber, $parentCategory->id);
        }
    }

    private function create_category_level_two($campus, course_discipline $course_discipline, $idnumber_level_two) {
        global $DB;

        $parentCategory = $DB->get_record('course_categories', ['idnumber' => "{$campus->id_campus}.{$course_discipline->course_id}"]);
        if ($parentCategory && !$this->category_exists($idnumber_level_two)) {
            $period = $this->removerZeroNoPeriodo($course_discipline->period);
            $this->create_category($period, $idnumber_level_two, $parentCategory->id);
        }
    }

    private function generate_category_level_three_id(campus $campus, course_discipline $course_discipline ) {
        return "{$campus->id_campus}.{$course_discipline->course_id}.{$course_discipline->period}.{$course_discipline->semester_offered}";
    }

    private function create_category_level_three(campus $campus, course_discipline $course_discipline) {
        global $DB;

        // algumas vezes o semestre_oferta_disciplina está vazio
        if (isset($course_discipline->period) && isset($course_discipline->semester_offered)) {
            $idnumber_parent = "{$campus->id_campus}.{$course_discipline->course_id}.{$course_discipline->period}";
            $parentCategory = $DB->get_record('course_categories', ['idnumber' => $idnumber_parent]);

            if ($parentCategory) {
                // Gera o identificador do nível 3
                $idnumber = $this->generate_category_level_three_id($campus, $course_discipline);

                if (substr($course_discipline->period, -2) !== '/0' && $course_discipline->semester_offered === '0') {
                    $name = "Optativas";
                } else {
                    $name = "{$course_discipline->semester_offered}" . $this->get_year_or_semester_suffix($course_discipline->period);
                }
                if (!$this->category_exists($idnumber)) {
                    $this->create_category($name, $idnumber, $parentCategory->id);

                }
            }
        }
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


    private function category_exists($idnumber) {
        global $DB;
        return $DB->record_exists('course_categories', ['idnumber' => $idnumber]);
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
