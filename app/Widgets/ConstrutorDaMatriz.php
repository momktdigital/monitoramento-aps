<?php

namespace App\Widgets;

use App\Enums\QuadranteIpf;
use App\Models\IndiceMunicipio;
use Illuminate\Support\Collection;

/**
 * Dados do gráfico da matriz INA × IDAPS (um ponto por município que tem os dois índices).
 */
class ConstrutorDaMatriz
{
    /**
     * @param  Collection<int, IndiceMunicipio>  $linhas
     * @param  array{ina: float, idaps: float}|null  $cortes
     * @return array<string, mixed>
     */
    public function construir(Collection $linhas, ?array $cortes, ?int $selecionado): array
    {
        $pontos = $linhas
            ->filter(fn (IndiceMunicipio $linha): bool => $linha->ipf_quadrante !== null)
            ->map(fn (IndiceMunicipio $linha): array => [
                'id' => $linha->municipio_id,
                'nome' => $linha->municipio->nome,
                'regiao' => $linha->municipio->regiao_saude_nome,
                'ina' => $linha->ina,
                'idaps' => $linha->idaps,
                'quadrante' => $linha->ipf_quadrante->value,
                'sem_resultado' => $linha->estrutura_sem_resultado,
            ])
            ->values()
            ->all();

        return [
            'modo' => 'matriz',
            'pontos' => $pontos,
            'cortes' => $cortes,
            'selecionado' => $selecionado,
            'quadrantes' => collect(QuadranteIpf::cases())->mapWithKeys(fn (QuadranteIpf $q): array => [$q->value => $q->rotulo()])->all(),
        ];
    }
}
