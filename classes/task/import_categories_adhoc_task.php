<?php
namespace local_sigaaintegration\task;

use core\task\adhoc_task;
use local_sigaaintegration\sigaa_categories_sync;
use local_sigaaintegration\sigaa_academic_period;

class import_categories_adhoc_task extends adhoc_task {

    /**
    * Get a descriptive name for this task (shown to admins).
    *
    * @return string
    */
    public function get_name() {
        return get_string('importcategories', 'local_sigaaintegration');
    }

    public function retry_until_success(): bool {
        return false;
    }

    public function execute() {
        $period = sigaa_academic_period::getAcademicPeriod();
        $categoriessync = new sigaa_categories_sync($period->getAno(), $period->getPeriodo());
        $categoriessync->sync();
    }

}

