<?php
namespace local_sigaaintegration;

use core\context;
use core_user;
use Exception;


class course_moodle
{
    public function __construct() {
    }

    public function create_course_for_discipline($disciplina, $student, $id_category, $idnumber) {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        try {
            $turma = str_replace(' ', '', $disciplina['turma']);
            $periodo = $this->removerZeroNoPeriodo($disciplina['periodo']);
            $fullname = "{$disciplina['disciplina']} / {$disciplina['semestre_oferta_disciplina']}" . $this->get_year_or_semester_suffix($disciplina['periodo']) . " / {$periodo}";
            $shortname = "{$disciplina['cod_disciplina']} / {$student['id_curso']} / {$turma} / {$disciplina['semestre_oferta_disciplina']}" . $this->get_year_or_semester_suffix($disciplina['periodo']) . " / {$disciplina['periodo']}";

            $newCourse = (object)[
                'fullname' => $fullname,
                'shortname' => $shortname,
                'category' => $id_category,
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
        } catch (Exception $exception) {
            mtrace('ERRO: Falha ao importar disciplina. erro:' . $exception->getMessage());
        }

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


    public function generate_category_level_three_id(campus $campus, $id_curso, $disciplina) {
        return "{$campus->id_campus}.{$id_curso}.{$disciplina['periodo']}.{$disciplina['semestre_oferta_disciplina']}";
    }

    private function get_year_or_semester_suffix($period) {
        return (substr($period, -1) === '0') ? 'º ano' : 'º semestre';
    }

    public function generate_course_idnumber(campus $campus, $enrollment, $disciplina) {
        $turma = str_replace(' ', '', $disciplina['turma']);
        return "{$campus->id_campus}.{$enrollment['id_curso']}.{$disciplina['id_disciplina']}.{$turma}.{$disciplina['periodo']}.{$disciplina['semestre_oferta_disciplina']}";

    }

}