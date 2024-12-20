<?php
namespace local_sigaaintegration;

use core\context;
use core_user;
use core_course_category;
use Exception;
use stdClass;

class category_moodle
{
    private int $basecategoryid;
    private array $category_level_one_created = [];
    private array $category_level_two_created = [];
    private array $category_level_three_created = [];

    private sigaa_courses_manager $sigaa_courses;

    public function __construct($sigaa_courses_manager)
    {
        $this->basecategoryid = configuration::getIdCategoriaBase();
        $this->sigaa_courses = $sigaa_courses_manager;
    }

    public function create_category_campus(campus $campus, $courses): void {
        if (!$this->category_exists($campus->id_campus)) {
            $this->create_category($courses['campus_descricao'], $campus->id_campus, $this->basecategoryid);
        }
    }

    public function create_all_categories($campus, $enrollments) {
        if ($campus->scheduled_sync) {
            foreach ($enrollments as $enrollment) {
                if ($enrollment['status'] == 'ATIVO') {
                    // Evita recriação de nível 1
                    if (!in_array($enrollment['id_curso'], $this->category_level_one_created)) {
                        $this->create_category_level_one($campus, $enrollment);
                        $this->category_level_one_created[] = $enrollment['id_curso'];
                    }
                    // Loop único nas disciplinas para criar níveis 2 e 3
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
        } else {
            mtrace("Sincronização desativada para o campus: " . $campus->description);
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

                if (substr($disciplina['periodo'], -2) !== '/0' && $disciplina['semestre_oferta_disciplina'] === '0') {
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

}