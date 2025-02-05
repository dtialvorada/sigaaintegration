<?php
/**
 *
 * @package   local_sigaaintegration
 * @copyright 2024, Cassiano Doneda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sigaaintegration;

use core_course_category;

set_time_limit(5000);

class sigaa_students_sync extends sigaa_base_sync
{
    private string $ano;
    private string $periodo;
    private array $courseNotFound = [];
    private $course_discipline_mapper;
    private user_moodle $user_moodle;

    public function __construct(string $year, string $period)
    {
        parent::__construct();
        $this->ano = $year;
        $this->periodo = $period;
        $this->user_moodle = new user_moodle();
        $this->course_discipline_mapper = new course_discipline_mapper();
    }

    protected function get_records($campus): array
    {
        $periodoletivo = sigaa_academic_period::buildFromParameters($this->ano, $this->periodo);
        return $this->api_client->get_enrollments($campus, $periodoletivo);
    }

    protected function process_records(campus $campus, array $records): void
    {
        mtrace("Processando dados: ". $campus->description);
        foreach ($records as $key => $record) {
            try {
                $this->insert_student_if_course_exists($campus, $records);
            } catch (Exception $e) {
                mtrace(sprintf(
                    'ERRO: Falha ao processar o estudante. Matrícula: %s, erro: %s',
                    $key,
                    $e->getMessage()
                ));
            }
        }
    }

    private function validate(array $discipline): bool {
        // Valida os campos necessários da disciplina
        return isset($discipline['periodo']) &&
            isset($discipline['semestre_oferta_disciplina']) &&
            $discipline['semestre_oferta_disciplina'] !== null &&
            isset($discipline['turma']) &&
            $discipline['turma'] !== null;
    }

    private function insert_student_if_course_exists($campus, array $enrollments): void
    {
        foreach ($enrollments as $record) {
            foreach ($record['disciplinas'] as $course_enrollment) {
                try {
                    if($this->validate($course_enrollment)) {
                        // generate_course_idnumber(campus $campus, $enrollment, $disciplina);
                        $course_discipline = $this->course_discipline_mapper->map_to_course_discipline($record, $course_enrollment);
                        $courseidnumber = $course_discipline->generate_course_idnumber($campus);
                        //mtrace($courseidnumber);
                        if($this->search_course($courseidnumber)) {
                            //inserir usuario no moodle
                            $this->user_moodle->insert($record);
                            continue;//inserindo a primeira vez, não preciso olhar o restante das disciplinas para esse usuario.
                        }
                    }
                } catch (Exception $e) {
                    mtrace(sprintf(
                        'ERRO: Falha ao processar criação de estudante no moodle. ' .
                        'Usuário: %s, usuário: %s, disciplina: %s, erro: %s',
                        $record['matricula'],
                        $record['nome_completo'],
                        $courseidnumber,
                        $e->getMessage()
                    ));
                }
            }
        }
    }

    /**
     * Busca disciplina pelo código de integração.
     */
    private function search_course(string $courseidnumber): ?object
    {
        /**
         * Evita busca repetida por disciplinas não encontradas.
         */
        if (array_search($courseidnumber, $this->courseNotFound)) {
            return null;
        }
        $results = core_course_category::search_courses(['search' => $courseidnumber]);
        if (count($results) > 0) {
            return current($results);
        }

        $this->courseNotFound[] = $courseidnumber;
        return null;
    }

    // protected function process_records(campus $campus, array $records): void
    // {
    //     mtrace("Processando dados: ". $campus->description);
    //     foreach ($records as $key => $record) {
    //         try {
    //             $this->user_moodle->insert($record);
    //         } catch (Exception $e) {
    //             mtrace(sprintf(
    //                 'ERRO: Falha ao processar o estudante. Matrícula: %s, erro: %s',
    //                 $key,
    //                 $e->getMessage()
    //             ));
    //         }
    //     }
    // }
}
