<?php

namespace App\Support;

class Numero
{
    /**
     * Converte números vindos de fontes públicas (inclusive textos como "71,449" ou "98,5") em float.
     * Retorna null para vazio, traço, reticências, "X" e qualquer valor não numérico.
     */
    public static function analisar(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return is_finite((float) $valor) ? (float) $valor : null;
        }

        if (! is_string($valor)) {
            return null;
        }

        $texto = trim($valor);

        if ($texto === '' || ! preg_match('/^-?[\d.,]+$/', $texto)) {
            return null;
        }

        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $texto)) {
            $texto = str_replace(',', '', $texto);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $texto)) {
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        } else {
            $texto = str_replace(',', '.', $texto);
        }

        return is_numeric($texto) ? (float) $texto : null;
    }

    /**
     * Competência AAAAMM a partir de "MM/AAAA" ou "AAAAMM".
     */
    public static function competencia(string $texto): ?int
    {
        $texto = trim($texto);

        if (preg_match('/^(\d{2})\/(\d{4})$/', $texto, $m)) {
            return (int) ($m[2].$m[1]);
        }

        if (preg_match('/^\d{6}$/', $texto)) {
            return (int) $texto;
        }

        return null;
    }
}
