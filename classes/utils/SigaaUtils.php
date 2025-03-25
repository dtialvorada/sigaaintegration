<?php

namespace local_sigaaintegration\utils;

class SigaaUtils {

    public static function removeZeroInPeriod($period): string {
        return (substr($period, -2) === '/0') ? substr($period, 0, -2) : $period;
    }

    public static function getYearOrSemesterSuffix($period): string {
        return (substr($period, -1) === '0') ? 'º ano' : 'º semestre';
    }

    public static function validateDiscipline(array $discipline): bool {
        return isset($discipline['periodo']) &&
            isset($discipline['semestres_oferta']) &&
            ($discipline['semestres_oferta'] !== null || !empty($discipline['semestres_oferta'])) &&
            isset($discipline['turma']) &&
            $discipline['turma'] !== null;
    }
}