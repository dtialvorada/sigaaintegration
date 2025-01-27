<?php
/**
 *
 * @package   local_sigaaintegration
 * @copyright 2024, Cassiano Doneda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sigaaintegration;

class sigaa_students_sync extends sigaa_base_sync
{
    private string $ano;
    private string $periodo;

    private user_moodle $user_moodle;

    public function __construct(string $year, string $period)
    {
        parent::__construct();
        $this->ano = $year;
        $this->periodo = $period;
        $this->user_moodle = new user_moodle();
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
                $this->user_moodle->insert($record);
            } catch (Exception $e) {
                mtrace(sprintf(
                    'ERRO: Falha ao processar o estudante. Matrícula: %s, erro: %s',
                    $key,
                    $e->getMessage()
                ));
            }
        }
    }
}
