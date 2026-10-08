<?php

namespace App\Widgets;

use App\Models\IndiceMunicipio;

/**
 * Dados do gráfico que compara as notas (0 a 100) dos municípios escolhidos, lado a lado.
 */
class ConstrutorDaComparacao
{
    /** Notas comparadas: rótulo => coluna de `indices_municipio`. */
    public const NOTAS = [
        'Necessidade (INA)' => 'ina',
        'Desempenho (IDAPS)' => 'idaps',
        'Estrutura e cobertura' => 'idaps_estrutura',
        'Resultados em saúde' => 'idaps_resultado',
    ];

    /**
     * @param  array<int, string>  $nomes  município => nome
     * @param  array<int, int>  $cores  município => posição da cor (0 a 3), estável enquanto o município estiver na comparação
     * @param  array<int, IndiceMunicipio>  $indices  município => índices do mês de referência
     * @return array<string, mixed>
     */
    public function construir(array $nomes, array $cores, array $indices): array
    {
        $series = [];

        foreach ($nomes as $id => $nome) {
            $series[] = [
                'nome' => $nome,
                'cor' => $cores[$id] ?? 0,
                'dados' => array_map(fn (string $coluna): ?float => ($indices[$id] ?? null)?->{$coluna}, array_values(self::NOTAS)),
            ];
        }

        return ['modo' => 'comparacao', 'categorias' => array_keys(self::NOTAS), 'series' => $series];
    }
}
