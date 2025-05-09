<?php
namespace local_sigaaintegration;

use core\context;
use core_user;
use Exception;

abstract class sigaa_base_sync
{
    //Lista de Campi
    private array $clientlist = [];

    protected sigaa_api_client $api_client;

    public function __construct(){
        $this->clientlist = configuration::getClientListConfig();
        $this->api_client = sigaa_api_client::create();
    }
    abstract protected function get_records(campus $campus): array;
    abstract protected function process_records(campus $campus, array $records): void;

    public function sync(): void
    {
        global $DB;
        if($this->clientlist) {
            foreach ($this->clientlist as $campus) {
                if ($campus->scheduled_sync) {
                    mtrace("Campus " . $campus->description . ": Início da Sincronização...");

                    $records = $this->get_records($campus);

                    mtrace('INFO: Início da sincronização. Total de registros: ' . count($records));

                    $batchSize = 100;
                    $batches = array_chunk($records, $batchSize);

                    foreach ($batches as $index => $batch) {
                        mtrace("INFO: Processando lote " . ($index + 1) . " de " . ceil(count($records) / $batchSize));

                        $transaction = $DB->start_delegated_transaction();
                        try {
                            $this->process_records($campus, $batch);
                            $transaction->allow_commit();
                        } catch (Exception $e) {
                            $transaction->rollback($e);
                            mtrace('ERROR: Transação falhou. ' . $e->getMessage());
                        }

                        gc_collect_cycles(); // Liberar memória após cada lote
                    }
                } else {
                    mtrace("Campus " . $campus->description . ": Sincronização DESATIVADA!");
                }
                mtrace('----------------------------------------');
            }
        } else {
            mtrace('INFO: Nenhum campus configurado para sincronização.');
        }

        mtrace('INFO: Processo de sincronização finalizada.');
    }
}
