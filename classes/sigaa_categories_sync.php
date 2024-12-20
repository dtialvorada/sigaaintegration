<?php
namespace local_sigaaintegration;

use core\context;
use core_course_category;
use dml_exception;
use Exception;
use moodle_exception;

class sigaa_categories_sync extends sigaa_base_sync{

    private string $ano;
    private string $periodo;

    private sigaa_api_client $data_api;
    private sigaa_courses_manager $sigaa_courses;

    private category_moodle $category_moodle;

    public function __construct(string $ano, string $periodo) {
        parent::__construct();
        $this->ano = $ano;
        $this->periodo = $periodo;
        $this->data_api = sigaa_api_client::create();
    }

    protected function get_records($client_api, campus $campus): array
    {
        mtrace('INFO: Importando disciplinas e categorias...');
        $this->sigaa_courses = new sigaa_courses_manager($this->data_api);
        $periodoletivo = sigaa_periodo_letivo::buildFromParameters($this->ano, $this->periodo);
        return $client_api->get_enrollments($campus, $periodoletivo);
    }

    //TODO ajustar a função process para executar corretamente somente a criação da categoria
    // está errado desta forma
    protected function process_records(array $records, $campus): void
    {
        try {
            $this->category_moodle = new category_moodle($this->sigaa_courses);
            $courses = $this->sigaa_courses->get_courses_by_campus($campus);
            $this->category_moodle->create_category_campus($campus, $courses);
            $this->category_moodle->create_all_categories($campus, $records);
        } catch (Exception $e) {
            mtrace(sprintf(
                'ERROR: Falha ao criar categorias, erro: %s',
                $e->getMessage()
            ));
        }
    }
}
