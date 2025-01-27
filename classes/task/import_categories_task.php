<?php
namespace local_sigaaintegration\task;

use core\task\scheduled_task;
use local_sigaaintegration\sigaa_categories_sync;
use local_sigaaintegration\sigaa_courses_sync;
use local_sigaaintegration\sigaa_periodo_letivo;

class import_categories_task extends scheduled_task {

    /**
    * Get a descriptive name for this task (shown to admins).
    *
    * @return string
    */
    public function get_name() {
        return get_string('importcategories', 'local_sigaaintegration');
    }

    public function execute() {
        $period = sigaa_periodo_letivo::getAcademicPeriod();
        $categoriessync = new sigaa_categories_sync($period->getAno(), $period->getPeriodo());
        $categoriessync->sync();
    }

}

