<?php
namespace local_sigaaintegration;

use core\context;
use core_user;
use dml_exception;
use Exception;

use local_sigaaintegration\utils\SigaaUtils;
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
        $academic_period = sigaa_academic_period::buildFromParameters($this->year, $this->period);
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
                        'visible' => $campus->coursevisibility,
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
                if (SigaaUtils::validateDiscipline($discipline)) {
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
            isset($discipline['semestres_oferta']) &&
            ($discipline['semestres_oferta'] !== null || !empty($discipline['semestres_oferta'])) &&
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
}
