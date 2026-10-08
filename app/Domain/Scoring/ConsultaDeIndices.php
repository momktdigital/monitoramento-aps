<?php

namespace App\Domain\Scoring;

use App\Enums\QuadranteIpf;
use App\Models\IndiceMunicipio;
use App\Models\Metodologia;
use Illuminate\Support\Collection;

/**
 * Leituras dos índices pré-calculados (INA, IDAPS e IPF) para as telas de análise.
 */
class ConsultaDeIndices
{
    /**
     * Meses de referência com índice calculado para a UF, do mais recente para o mais antigo.
     *
     * @return list<int>
     */
    public function competencias(int $codigoUf): array
    {
        return IndiceMunicipio::query()
            ->join('municipios', 'municipios.id', '=', 'indices_municipio.municipio_id')
            ->where('municipios.codigo_uf', $codigoUf)
            ->distinct()
            ->orderByDesc('indices_municipio.competencia')
            ->pluck('indices_municipio.competencia')
            ->map(fn ($competencia): int => (int) $competencia)
            ->all();
    }

    /**
     * Índices de todos os municípios da UF em um mês de referência.
     *
     * @return Collection<int, IndiceMunicipio> indexados pelo município
     */
    public function doMes(int $codigoUf, int $competencia): Collection
    {
        return IndiceMunicipio::query()
            ->join('municipios', 'municipios.id', '=', 'indices_municipio.municipio_id')
            ->where('municipios.codigo_uf', $codigoUf)
            ->where('municipios.ativo', true)
            ->where('indices_municipio.competencia', $competencia)
            ->select('indices_municipio.*')
            ->with('municipio:id,nome,regiao_saude_nome,regiao_saude_codigo,piloto,codigo_uf')
            ->get()
            ->keyBy('municipio_id');
    }

    /**
     * Histórico do município, do mês mais antigo ao mais recente.
     *
     * @return Collection<int, IndiceMunicipio>
     */
    public function serie(int $municipioId): Collection
    {
        return IndiceMunicipio::query()
            ->where('municipio_id', $municipioId)
            ->orderBy('competencia')
            ->get();
    }

    /**
     * Pontos que separam "alto" de "baixo" na matriz, calculados como no cálculo dos índices (só com os municípios
     * que têm os dois índices).
     *
     * @param  Collection<int, IndiceMunicipio>  $linhas
     * @return array{ina: float, idaps: float}|null null quando nenhum município tem os dois índices
     */
    public function cortes(Collection $linhas): ?array
    {
        $completas = $linhas->filter(fn (IndiceMunicipio $linha): bool => $linha->ina !== null && $linha->idaps !== null);

        if ($completas->isEmpty()) {
            return null;
        }

        $regra = Metodologia::ativa()->first()?->configuracao['ipf']['corte'] ?? 'mediana';

        return [
            'ina' => MatrizIpf::corte($completas->pluck('ina')->map(fn ($v): float => (float) $v)->all(), $regra),
            'idaps' => MatrizIpf::corte($completas->pluck('idaps')->map(fn ($v): float => (float) $v)->all(), $regra),
        ];
    }

    /**
     * @param  Collection<int, IndiceMunicipio>  $linhas
     * @return array<int, int> quadrante (valor do enum) => quantidade de municípios
     */
    public function resumoDosQuadrantes(Collection $linhas): array
    {
        $resumo = array_fill_keys(array_map(fn (QuadranteIpf $q): int => $q->value, QuadranteIpf::cases()), 0);

        foreach ($linhas as $linha) {
            if ($linha->ipf_quadrante !== null) {
                $resumo[$linha->ipf_quadrante->value]++;
            }
        }

        return $resumo;
    }

    /**
     * Municípios com prioridade calculada, do mais prioritário ao menos prioritário (empate: nome).
     *
     * @param  Collection<int, IndiceMunicipio>  $linhas
     * @return Collection<int, IndiceMunicipio>
     */
    public function porPrioridade(Collection $linhas): Collection
    {
        return $linhas
            ->filter(fn (IndiceMunicipio $linha): bool => $linha->ipf_pontuacao !== null)
            ->sort(fn (IndiceMunicipio $a, IndiceMunicipio $b): int => [$b->ipf_pontuacao, $a->municipio->nome] <=> [$a->ipf_pontuacao, $b->municipio->nome])
            ->values();
    }
}
