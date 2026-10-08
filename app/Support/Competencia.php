<?php

namespace App\Support;

class Competencia
{
    private const MESES = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /**
     * Texto amigável para uma competência AAAAMM (ex.: 202607 => "jul/2026").
     */
    public static function rotulo(?int $competencia): string
    {
        if ($competencia === null) {
            return '—';
        }

        $mes = $competencia % 100;
        $ano = intdiv($competencia, 100);

        return isset(self::MESES[$mes]) && $mes >= 1 ? self::MESES[$mes].'/'.$ano : (string) $competencia;
    }
}
