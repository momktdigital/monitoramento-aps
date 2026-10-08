<?php

namespace App\Domain\Indicators;

use App\Enums\EscopoBenchmark;
use App\Models\Benchmark;
use App\Models\ValorIndicador;
use Illuminate\Support\Facades\DB;

/**
 * Calcula média, mediana e quartis de cada indicador por região de saúde, UF e Brasil, e grava em
 * `benchmarks`. É executado ao fim de cada carga, para o painel nunca agregar em tempo de requisição.
 */
class CalculadorDeBenchmarks
{
    private const TOTAL_DE_UFS = 27;

    private const COLUNAS_ATUALIZAVEIS = ['quantidade', 'media', 'mediana', 'p25', 'p75', 'minimo', 'maximo'];

    /**
     * @param  array<int, list<int>>  $competenciasPorIndicador  indicador_id => competências a recalcular
     * @return int quantidade de linhas de benchmark gravadas
     */
    public function calcular(array $competenciasPorIndicador): int
    {
        $gravadas = 0;

        foreach ($competenciasPorIndicador as $indicadorId => $competencias) {
            $linhas = ValorIndicador::query()
                ->join('municipios', 'municipios.id', '=', 'valores_indicador.municipio_id')
                ->where('valores_indicador.indicador_id', $indicadorId)
                ->whereIn('valores_indicador.competencia', $competencias)
                ->whereNotNull('valores_indicador.valor')
                ->where('municipios.ativo', true)
                ->get(['valores_indicador.competencia', 'valores_indicador.valor', 'municipios.codigo_uf', 'municipios.regiao_saude_codigo']);

            $porCompetencia = $linhas->groupBy('competencia');

            foreach ($porCompetencia as $competencia => $doMes) {
                $gravadas += $this->gravarEscopos($indicadorId, (int) $competencia, $doMes->all());
            }
        }

        return $gravadas;
    }

    /**
     * Recalcula tudo o que existe (uso administrativo, depois de correções na metodologia).
     */
    public function recalcularTudo(): int
    {
        $competencias = DB::table('valores_indicador')
            ->select('indicador_id', 'competencia')
            ->distinct()
            ->get()
            ->groupBy('indicador_id')
            ->map(fn ($linhas): array => $linhas->pluck('competencia')->map(fn ($c): int => (int) $c)->all())
            ->all();

        return $this->calcular($competencias);
    }

    /**
     * @param  list<ValorIndicador>  $linhas
     */
    private function gravarEscopos(int $indicadorId, int $competencia, array $linhas): int
    {
        $grupos = [];

        foreach ($linhas as $linha) {
            $valor = (float) $linha->valor;
            $grupos[EscopoBenchmark::Uf->value][(int) $linha->codigo_uf][] = $valor;

            if ($linha->regiao_saude_codigo !== null) {
                $grupos[EscopoBenchmark::RegiaoSaude->value][(int) $linha->regiao_saude_codigo][] = $valor;
            }
        }

        $ufsPresentes = count($grupos[EscopoBenchmark::Uf->value] ?? []);

        if ($ufsPresentes >= self::TOTAL_DE_UFS) {
            $grupos[EscopoBenchmark::Brasil->value][0] = array_merge(...array_values($grupos[EscopoBenchmark::Uf->value]));
        }

        $registros = [];

        foreach ($grupos as $escopo => $porId) {
            foreach ($porId as $escopoId => $valores) {
                $registros[] = ['indicador_id' => $indicadorId, 'competencia' => $competencia, 'escopo' => $escopo, 'escopo_id' => $escopoId] + $this->estatisticas($valores);
            }
        }

        if ($registros === []) {
            return 0;
        }

        Benchmark::upsert($registros, Benchmark::CHAVE_UNICA, self::COLUNAS_ATUALIZAVEIS);

        return count($registros);
    }

    /**
     * @param  list<float>  $valores
     * @return array{quantidade: int, media: float, mediana: float, p25: float, p75: float, minimo: float, maximo: float}
     */
    private function estatisticas(array $valores): array
    {
        sort($valores);

        return [
            'quantidade' => count($valores),
            'media' => round(array_sum($valores) / count($valores), 4),
            'mediana' => round(self::percentil($valores, 0.5), 4),
            'p25' => round(self::percentil($valores, 0.25), 4),
            'p75' => round(self::percentil($valores, 0.75), 4),
            'minimo' => round($valores[0], 4),
            'maximo' => round($valores[array_key_last($valores)], 4),
        ];
    }

    /**
     * Percentil por interpolação linear (mesmo método de Excel/NumPy). Espera valores ordenados.
     *
     * @param  list<float>  $ordenados
     */
    public static function percentil(array $ordenados, float $proporcao): float
    {
        $posicao = (count($ordenados) - 1) * $proporcao;
        $abaixo = (int) floor($posicao);
        $acima = (int) ceil($posicao);

        return $ordenados[$abaixo] + ($ordenados[$acima] - $ordenados[$abaixo]) * ($posicao - $abaixo);
    }
}
