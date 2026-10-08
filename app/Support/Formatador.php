<?php

namespace App\Support;

use App\Models\Indicador;

/**
 * Formatação de valores para leitura humana, no padrão brasileiro (vírgula decimal, ponto de milhar).
 */
class Formatador
{
    public static function numero(float $valor, int $casas = 0): string
    {
        $texto = number_format(abs($valor), $casas, ',', '.');

        return $valor < 0 && (float) str_replace(['.', ','], ['', '.'], $texto) !== 0.0 ? '−'.$texto : $texto;
    }

    /**
     * Valor com a unidade do indicador: "82,5%", "R$ 1.234,56", "23 equipes", "3,2 por 10 mil hab.".
     */
    public static function valor(float $valor, Indicador $indicador): string
    {
        $numero = self::numero($valor, $indicador->casas_decimais);

        return match ($indicador->unidade) {
            '%' => $numero.'%',
            'R$' => 'R$ '.$numero,
            default => $numero.' '.$indicador->unidade,
        };
    }

    /**
     * Variação absoluta entre dois valores, com sinal explícito. Percentuais usam "p.p." (pontos percentuais).
     */
    public static function variacao(float $de, float $para, Indicador $indicador): string
    {
        $diferenca = $para - $de;
        $sinal = $diferenca > 0 ? '+' : ($diferenca < 0 ? '−' : '');

        if ($indicador->unidade === '%') {
            return $sinal.self::numero(abs($diferenca), max(1, $indicador->casas_decimais)).' p.p.';
        }

        if ($de == 0.0) {
            return $sinal.self::numero(abs($diferenca), $indicador->casas_decimais);
        }

        return $sinal.self::numero(abs($diferenca / $de * 100), 1).'%';
    }

    public static function ordinal(int $posicao): string
    {
        return $posicao.'ª';
    }
}
