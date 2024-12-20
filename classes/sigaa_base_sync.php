<?php
namespace local_sigaaintegration;

use core\context;
use core_user;
use Exception;


abstract class sigaa_base_sync
{
    //Lista de Campi
    private array $clientlist = [];

    public function __construct(){
        $this->clientlist = configuration::getClientListConfig();
    }
    abstract protected function get_records($client_api, campus $campus): array;
    abstract protected function process_records(array $records, campus $campus): void;

    protected function get_api_client()
    {
        return sigaa_api_client::create();
    }

    public function sync(): void
    {
        global $DB;

        $client_api = $this->get_api_client();
        if($this->clientlist) {
            foreach ($this->clientlist as $campus) {
                mtrace("Campus " . $campus->description . " - Início da Sincronização...");
                if ($campus->scheduled_sync) {
                    $records = $this->get_records($client_api, $campus);

                    mtrace('INFO: Início da sincronização. Total de registros: ' . count($records));

                    $batchSize = 100;
                    $batches = array_chunk($records, $batchSize);

                    foreach ($batches as $index => $batch) {
                        mtrace("INFO: Processando lote " . ($index + 1) . " de " . ceil(count($records) / $batchSize));

                        $transaction = $DB->start_delegated_transaction();
                        try {
                            $this->process_records($batch, $campus);
                            $transaction->allow_commit();
                        } catch (Exception $e) {
                            $transaction->rollback($e);
                            mtrace('ERROR: Transação falhou. ' . $e->getMessage());
                        }

                        gc_collect_cycles(); // Liberar memória após cada lote
                    }
                }
            }
        } else {
            mtrace('INFO: Nenhum campus configurado para sincronização.');
        }

        mtrace('INFO: Processo de sincronização finalizada.');
    }
}
