<?php
namespace local_sigaaintegration;

class course_discipline_mapper
{
    /**
     * Mapeia os dados do aluno e da disciplina para um objeto `course_discipline`.
     *
     * @param array $student
     * @param array $discipline
     * @return course_discipline
     */
    public function map_to_course_discipline(array $student, array $discipline): course_discipline
    {
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
            $discipline["semestres_oferta"],
            $discipline["periodo"],
            $discipline["situacao_matricula"],
            $discipline["turma"],
            $discipline["modalidade_educacao_turma"],
            $discipline["turno_turma"]
        );
    }
}
