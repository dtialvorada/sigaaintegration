<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 * Período letivo
 *
 * @package   local_sigaaintegration
 * @copyright 2024, Igor Ferreira Cemim
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sigaaintegration;

class sigaa_periodo_letivo
{

    private string $ano;

    private string $periodo;

    private const SEPARADOR = '/';

    public function __construct(string $ano, string $periodo)
    {
        $this->ano = $ano;
        $this->periodo = $periodo;
    }

    public static function buildNew(): sigaa_periodo_letivo
    {
        //$periodoletivo = self::getPeriodoLetivoAtual();
        $periodoletivo = self::getPeriodoLetivoSettings();
        return new sigaa_periodo_letivo($periodoletivo['ano'], $periodoletivo['periodo']);
    }

    public static function buildFromParameters(string $ano, string $periodo): sigaa_periodo_letivo
    {
        return new sigaa_periodo_letivo($ano, $periodo);
    }

    public static function buildFromPeriodoFormatado(string $periodoletivo): sigaa_periodo_letivo
    {
        $parts = explode(self::SEPARADOR, $periodoletivo);
        return new sigaa_periodo_letivo($parts[0], $parts[1]);
    }

    private static function getPeriodoLetivoAtual(): array
    {
        return [
            //'ano' => date("Y"),
            //'periodo' => intval(date("m")) <= 6 ? 1 : 2,
            'ano' => 2024,
            'periodo' => 2,
        ];
    }

    // get field academic_period da pagina settings.php,
    private static function getPeriodoLetivoSettings(): array
    {
        $academicPeriod = configuration::getAcademicPeriod();//get from Other Settings -> Academic Period -> page settings.php 
        $result = preg_split("/\//", trim($academicPeriod), -1, PREG_SPLIT_NO_EMPTY);
        return [
            'ano' => $result[0],
            'periodo' => $result[1],
        ];
    }

    public function getAno(): string
    {
        return $this->ano;
    }

    public function getPeriodo(): string
    {
        return $this->periodo;
    }

    public function getPeriodoFormatado(): string
    {
        return $this->ano . self::SEPARADOR . $this->periodo;
    }

    public static function validate($periodoletivo): bool
    {
        if (empty($periodoletivo)) {
            return false;
        }

        $parts = explode(self::SEPARADOR, $periodoletivo);
        if (count($parts) < 2) {
            return false;
        }

        $ano = $parts[0];
        $periodo = $parts[1];
        if (strlen($ano) < 4) {
            return false;
        }
        if (strlen($periodo) > 1) {
            return false;
        }

        return true;
    }

}
