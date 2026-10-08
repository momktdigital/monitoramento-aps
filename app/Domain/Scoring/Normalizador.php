<?php

namespace App\Domain\Scoring;

/**
 * Transforma valores brutos (com unidades diferentes) em notas de 0 a 100 comparáveis entre si.
 *
 * A nota é a posição relativa do município entre os pares: o menor valor recebe 0 e o maior recebe 100. Valores
 * empatados dividem a mesma nota (a média das posições que ocupariam), então 20 municípios com 100% de cobertura
 * ficam todos na mesma nota, nem acima nem abaixo uns dos outros.
 */
class Normalizador
{
    /**
     * @param  array<int, float>  $valores  município => valor bruto
     * @return array<int, float> município => nota de 0 a 100 (valor alto = nota alta)
     */
    public static function notas(array $valores): array
    {
        $total = count($valores);

        if ($total === 0) {
            return [];
        }

        if ($total === 1) {
            return [array_key_first($valores) => 50.0];
        }

        $ordenados = array_map(fn (float $valor): float => round($valor, 4), array_values($valores));
        sort($ordenados);

        // Primeira posição (0 a n-1) e quantidade de cada valor distinto.
        $primeira = [];
        $quantidade = [];

        foreach ($ordenados as $posicao => $valor) {
            $chave = (string) $valor;
            $primeira[$chave] ??= $posicao;
            $quantidade[$chave] = ($quantidade[$chave] ?? 0) + 1;
        }

        $notas = [];

        foreach ($valores as $municipio => $valor) {
            $chave = (string) round($valor, 4);
            $posicaoMedia = $primeira[$chave] + ($quantidade[$chave] - 1) / 2;
            $notas[$municipio] = $posicaoMedia / ($total - 1) * 100;
        }

        return $notas;
    }

    /**
     * Inverte a nota dos indicadores em que valor baixo é o desejável (ou, no INA, em que valor baixo indica mais necessidade).
     */
    public static function inverter(float $nota): float
    {
        return 100 - $nota;
    }
}
