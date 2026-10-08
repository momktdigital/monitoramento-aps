<?php

namespace App\Domain\Indicators;

use App\Enums\EscopoBenchmark;
use App\Enums\Periodicidade;
use App\Enums\Polaridade;
use App\Models\Benchmark;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Models\ValorIndicador;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Leituras do painel. Cada consulta usa as chaves da tabela `valores_indicador` e as estatísticas
 * pré-calculadas em `benchmarks`: nada aqui agrega dados em tempo de requisição.
 */
class ConsultaDeIndicadores
{
    /** Indicadores anuais têm poucos pontos: a série mostra pelo menos cinco anos. */
    private const MESES_MINIMOS_PARA_INDICADOR_ANUAL = 60;

    /**
     * Valor mais recente do município para o indicador.
     *
     * @return array{competencia: int, valor: float}|null
     */
    public function ultimo(int $municipioId, Indicador $indicador): ?array
    {
        $linha = ValorIndicador::query()
            ->where('municipio_id', $municipioId)
            ->where('indicador_id', $indicador->id)
            ->whereNotNull('valor')
            ->orderByDesc('competencia')
            ->first(['competencia', 'valor']);

        return $linha === null ? null : ['competencia' => $linha->competencia, 'valor' => $linha->valor];
    }

    /**
     * Valor do município na competência de um ano antes (ou no ano anterior, nos indicadores anuais).
     *
     * @return array{competencia: int, valor: float}|null
     */
    public function umAnoAntes(int $municipioId, Indicador $indicador, int $competencia): ?array
    {
        $alvo = $competencia - 100;

        $linha = ValorIndicador::query()
            ->where('municipio_id', $municipioId)
            ->where('indicador_id', $indicador->id)
            ->whereNotNull('valor')
            ->where('competencia', '<=', $alvo)
            ->where('competencia', '>', $alvo - 100)
            ->orderByDesc('competencia')
            ->first(['competencia', 'valor']);

        return $linha === null ? null : ['competencia' => $linha->competencia, 'valor' => $linha->valor];
    }

    /**
     * Série do município terminando no valor mais recente, com a janela em meses contada a partir dele.
     *
     * @return list<array{competencia: int, valor: float}>
     */
    public function serie(int $municipioId, Indicador $indicador, int $meses): array
    {
        $ultimo = $this->ultimo($municipioId, $indicador);

        if ($ultimo === null) {
            return [];
        }

        $janela = $indicador->periodicidade === Periodicidade::Anual ? max($meses, self::MESES_MINIMOS_PARA_INDICADOR_ANUAL) : $meses;
        $inicio = (int) Carbon::createFromFormat('!Ym', (string) $ultimo['competencia'])->startOfMonth()->subMonthsNoOverflow($janela - 1)->format('Ym');

        return ValorIndicador::query()
            ->where('municipio_id', $municipioId)
            ->where('indicador_id', $indicador->id)
            ->whereNotNull('valor')
            ->whereBetween('competencia', [$inicio, $ultimo['competencia']])
            ->orderBy('competencia')
            ->get(['competencia', 'valor'])
            ->map(fn (ValorIndicador $linha): array => ['competencia' => $linha->competencia, 'valor' => $linha->valor])
            ->all();
    }

    public function benchmark(Indicador $indicador, int $competencia, EscopoBenchmark $escopo, int $escopoId): ?Benchmark
    {
        return Benchmark::query()
            ->where('indicador_id', $indicador->id)
            ->where('competencia', $competencia)
            ->where('escopo', $escopo->value)
            ->where('escopo_id', $escopoId)
            ->first();
    }

    /** Com menos municípios que isso, a "mediana" seria o próprio município (ou quase): não serve de comparação. */
    public const MINIMO_PARA_COMPARAR = 3;

    /**
     * Mediana do escopo em cada competência informada, só quando há municípios suficientes para comparar.
     *
     * @param  list<int>  $competencias
     * @return array<int, float> competência => mediana
     */
    public function medianas(Indicador $indicador, EscopoBenchmark $escopo, int $escopoId, array $competencias): array
    {
        if ($competencias === []) {
            return [];
        }

        return Benchmark::query()
            ->where('indicador_id', $indicador->id)
            ->where('escopo', $escopo->value)
            ->where('escopo_id', $escopoId)
            ->where('quantidade', '>=', self::MINIMO_PARA_COMPARAR)
            ->whereIn('competencia', $competencias)
            ->pluck('mediana', 'competencia')
            ->map(fn ($mediana): float => (float) $mediana)
            ->all();
    }

    /**
     * Valores de todos os municípios da região de saúde na competência, do melhor para o pior.
     *
     * @return list<array{municipio_id: int, nome: string, valor: float}>
     */
    public function ranking(Indicador $indicador, int $competencia, int $regiaoDeSaudeCodigo): array
    {
        $linhas = ValorIndicador::query()
            ->join('municipios', 'municipios.id', '=', 'valores_indicador.municipio_id')
            ->where('valores_indicador.indicador_id', $indicador->id)
            ->where('valores_indicador.competencia', $competencia)
            ->where('municipios.regiao_saude_codigo', $regiaoDeSaudeCodigo)
            ->where('municipios.ativo', true)
            ->whereNotNull('valores_indicador.valor')
            ->get(['municipios.id as municipio_id', 'municipios.nome', 'valores_indicador.valor'])
            ->map(fn ($linha): array => ['municipio_id' => (int) $linha->municipio_id, 'nome' => (string) $linha->nome, 'valor' => (float) $linha->valor])
            ->all();

        // Valores acima do teto empatam (ex.: 112% e 126% de cobertura); dentro do empate, vale o valor real.
        usort($linhas, function (array $a, array $b) use ($indicador): int {
            $ordem = $indicador->valorAvaliado($a['valor']) <=> $indicador->valorAvaliado($b['valor']);

            if ($ordem === 0) {
                $ordem = $a['valor'] <=> $b['valor'];
            }

            return $indicador->polaridade === Polaridade::MenorMelhor ? $ordem : -$ordem;
        });

        return $linhas;
    }

    /**
     * Posição do município entre todos os do estado (1 = melhor situação) e quantos têm dado.
     * Indicadores sem sentido bom/ruim não têm posição.
     *
     * @return array{posicao: int, total: int}|null
     */
    public function posicaoNoEstado(Indicador $indicador, int $competencia, Municipio $municipio, float $valor): ?array
    {
        if ($indicador->polaridade === Polaridade::Neutra) {
            return null;
        }

        $base = ValorIndicador::query()
            ->join('municipios', 'municipios.id', '=', 'valores_indicador.municipio_id')
            ->where('valores_indicador.indicador_id', $indicador->id)
            ->where('valores_indicador.competencia', $competencia)
            ->where('municipios.codigo_uf', $municipio->codigo_uf)
            ->where('municipios.ativo', true)
            ->whereNotNull('valores_indicador.valor');

        $total = (clone $base)->count();

        if ($total === 0) {
            return null;
        }

        $operador = $indicador->polaridade === Polaridade::MenorMelhor ? '<' : '>';
        $melhores = $indicador->teto === null
            ? (clone $base)->where('valores_indicador.valor', $operador, $valor)->count()
            : (clone $base)->whereRaw("LEAST(valores_indicador.valor, ?) {$operador} ?", [$indicador->teto, $indicador->valorAvaliado($valor)])->count();

        return ['posicao' => $melhores + 1, 'total' => $total];
    }

    /**
     * Último valor de cada município para cada indicador, em duas consultas (para comparar vários municípios de uma vez).
     *
     * @param  list<int>  $municipioIds
     * @param  list<int>  $indicadorIds
     * @return array<int, array<int, array{competencia: int, valor: float}>> município => indicador => último valor
     */
    public function ultimosValores(array $municipioIds, array $indicadorIds): array
    {
        if ($municipioIds === [] || $indicadorIds === []) {
            return [];
        }

        $maximos = DB::table('valores_indicador')
            ->whereIn('municipio_id', $municipioIds)
            ->whereIn('indicador_id', $indicadorIds)
            ->whereNotNull('valor')
            ->groupBy('municipio_id', 'indicador_id')
            ->select('municipio_id', 'indicador_id', DB::raw('MAX(competencia) as competencia'));

        $resultado = [];

        ValorIndicador::query()
            ->joinSub($maximos, 'ultimos', function ($join): void {
                $join->on('valores_indicador.municipio_id', '=', 'ultimos.municipio_id')
                    ->on('valores_indicador.indicador_id', '=', 'ultimos.indicador_id')
                    ->on('valores_indicador.competencia', '=', 'ultimos.competencia');
            })
            ->get(['valores_indicador.municipio_id', 'valores_indicador.indicador_id', 'valores_indicador.competencia', 'valores_indicador.valor'])
            ->each(function (ValorIndicador $linha) use (&$resultado): void {
                $resultado[$linha->municipio_id][$linha->indicador_id] = ['competencia' => $linha->competencia, 'valor' => $linha->valor];
            });

        return $resultado;
    }

    /**
     * Competência a mostrar no mapa: a mais recente em que pelo menos metade dos municípios do estado tem dado
     * (uma competência com poucos municípios daria um mapa quase vazio); sem isso, a mais recente com algum dado.
     */
    public function competenciaDoMapa(Indicador $indicador, int $codigoUf): ?int
    {
        $contagem = ValorIndicador::query()
            ->join('municipios', 'municipios.id', '=', 'valores_indicador.municipio_id')
            ->where('valores_indicador.indicador_id', $indicador->id)
            ->where('municipios.codigo_uf', $codigoUf)
            ->where('municipios.ativo', true)
            ->whereNotNull('valores_indicador.valor')
            ->groupBy('valores_indicador.competencia')
            ->orderByDesc('valores_indicador.competencia')
            ->selectRaw('valores_indicador.competencia as competencia, COUNT(*) as total')
            ->pluck('total', 'competencia');

        if ($contagem->isEmpty()) {
            return null;
        }

        $municipios = Municipio::query()->ativo()->where('codigo_uf', $codigoUf)->count();

        foreach ($contagem as $competencia => $total) {
            if ($total * 2 >= $municipios) {
                return (int) $competencia;
            }
        }

        return (int) $contagem->keys()->first();
    }

    /**
     * Valores de todos os municípios do estado em uma competência.
     *
     * @return array<int, float> município => valor
     */
    public function valoresDaCompetencia(Indicador $indicador, int $competencia, int $codigoUf): array
    {
        return ValorIndicador::query()
            ->join('municipios', 'municipios.id', '=', 'valores_indicador.municipio_id')
            ->where('valores_indicador.indicador_id', $indicador->id)
            ->where('valores_indicador.competencia', $competencia)
            ->where('municipios.codigo_uf', $codigoUf)
            ->where('municipios.ativo', true)
            ->whereNotNull('valores_indicador.valor')
            ->pluck('valores_indicador.valor', 'valores_indicador.municipio_id')
            ->map(fn ($valor): float => (float) $valor)
            ->all();
    }
}
