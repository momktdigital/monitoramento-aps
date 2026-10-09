<?php

namespace App\Domain\Scoring;

use App\Administracao\VerificadorDeSaude;
use App\Domain\Indicators\CalculadorDeBenchmarks;
use App\Models\Benchmark;
use App\Models\Indicador;
use App\Models\IndiceMunicipio;
use App\Models\Metodologia;
use App\Models\Municipio;
use App\Models\ValorIndicador;
use App\Support\VersaoDosDados;
use Illuminate\Support\Facades\DB;

/**
 * Calcula INA, IDAPS e IPF de cada município, para cada mês de referência, e grava o resultado pronto para consulta.
 *
 * Para um mês de referência, cada indicador usa o último dado conhecido até aquele mês (se ainda dentro da validade).
 * Os pares de comparação são os municípios do mesmo estado. As regras de peso, validade e corte vêm da metodologia
 * ativa (`config/indices.php`, versionada em `metodologias`); aqui só há a mecânica.
 *
 * Além de `indices_municipio`, as três séries principais são gravadas como indicadores calculados
 * (`indice_ina`, `indice_idaps`, `indice_prioridade`) para aparecerem nos visuais do painel, com benchmarks e análise.
 */
class CalculadorDeIndices
{
    /** Séries gravadas no catálogo de indicadores: coluna de `indices_municipio` => código do indicador. */
    public const INDICADORES_DERIVADOS = [
        'ina' => 'indice_ina',
        'idaps' => 'indice_idaps',
        'ipf_pontuacao' => 'indice_prioridade',
    ];

    private const TAMANHO_DO_LOTE = 500;

    public function __construct(private readonly CalculadorDeBenchmarks $benchmarks) {}

    /**
     * Informa se uma carga que alterou estes indicadores (ids) muda os índices.
     *
     * @param  list<int>  $indicadorIds
     */
    public function afetadoPor(array $indicadorIds): bool
    {
        if ($indicadorIds === []) {
            return false;
        }

        $codigos = array_keys($this->componentes((array) config('indices')));

        return Indicador::query()->whereIn('codigo', $codigos)->whereIn('id', $indicadorIds)->exists();
    }

    /**
     * @return array{metodologia: int, linhas: int, ultima_competencia: int|null, quadrantes: array<int, int>, avisos: list<string>}
     */
    public function calcular(?Metodologia $metodologia = null): array
    {
        $metodologia ??= Metodologia::sincronizar();
        $configuracao = $metodologia->configuracao;
        $avisos = [];

        $componentes = $this->componentes($configuracao);
        $indicadores = Indicador::query()->ativo()->whereIn('codigo', array_keys($componentes))->get()->keyBy('codigo');
        $estados = Municipio::query()->ativo()->get(['id', 'codigo_uf'])->groupBy('codigo_uf');
        $series = $this->carregarSeries($indicadores->pluck('id')->all(), $estados->flatten()->pluck('id')->all());

        $linhas = [];

        foreach ($estados as $codigoUf => $doEstado) {
            $ids = $doEstado->pluck('id')->map(fn ($id): int => (int) $id)->all();

            if (count($ids) < $configuracao['minimo_de_pares']) {
                $avisos[] = "UF {$codigoUf}: apenas ".count($ids).' municípios cadastrados; são necessários '.$configuracao['minimo_de_pares'].' para comparar.';

                continue;
            }

            array_push($linhas, ...$this->calcularEstado($metodologia, $indicadores->all(), $componentes, $series, $ids, $avisos));
        }

        $this->gravar($linhas);

        $ultima = $linhas === [] ? null : max(array_column($linhas, 'competencia'));
        $quadrantes = [];

        foreach ($linhas as $linha) {
            if ($linha['competencia'] === $ultima && $linha['ipf_quadrante'] !== null) {
                $quadrantes[$linha['ipf_quadrante']] = ($quadrantes[$linha['ipf_quadrante']] ?? 0) + 1;
            }
        }

        ksort($quadrantes);

        VerificadorDeSaude::indicesAtualizados();

        return ['metodologia' => $metodologia->versao, 'linhas' => count($linhas), 'ultima_competencia' => $ultima, 'quadrantes' => $quadrantes, 'avisos' => $avisos];
    }

    /**
     * Indicadores usados pela metodologia, com o que cada um precisa saber para ser avaliado.
     *
     * @param  array<string, mixed>  $configuracao
     * @return array<string, array{validade_meses: int}>
     */
    private function componentes(array $configuracao): array
    {
        $componentes = [];

        foreach (['ina', 'idaps'] as $indice) {
            foreach ($configuracao[$indice]['pilares'] ?? [] as $pilar) {
                foreach ($pilar['componentes'] as $codigo => $componente) {
                    $componentes[$codigo] ??= ['validade_meses' => (int) ($componente['validade_meses'] ?? 0)];
                }
            }
        }

        return $componentes;
    }

    /**
     * @param  list<int>  $indicadorIds
     * @param  list<int>  $municipioIds
     * @return array<int, array<int, list<array{0: int, 1: float}>>> indicador => município => [[competência, valor]] em ordem crescente
     */
    private function carregarSeries(array $indicadorIds, array $municipioIds): array
    {
        $series = [];

        if ($indicadorIds === [] || $municipioIds === []) {
            return $series;
        }

        $consulta = ValorIndicador::query()
            ->whereIn('indicador_id', $indicadorIds)
            ->whereIn('municipio_id', $municipioIds)
            ->whereNotNull('valor')
            ->orderBy('competencia')
            ->select(['municipio_id', 'indicador_id', 'competencia', 'valor']);

        foreach ($consulta->cursor() as $linha) {
            $series[$linha->indicador_id][$linha->municipio_id][] = [$linha->competencia, $linha->valor];
        }

        return $series;
    }

    /**
     * @param  array<string, Indicador>  $indicadores
     * @param  array<string, array{validade_meses: int}>  $componentes
     * @param  array<int, array<int, list<array{0: int, 1: float}>>>  $series
     * @param  list<int>  $ids  municípios do estado (os pares)
     * @param  list<string>  $avisos
     * @return list<array<string, mixed>> linhas prontas para `indices_municipio`
     */
    private function calcularEstado(Metodologia $metodologia, array $indicadores, array $componentes, array $series, array $ids, array &$avisos): array
    {
        $configuracao = $metodologia->configuracao;
        $necessarios = (int) ceil($configuracao['cobertura_minima_dos_pares'] * count($ids));

        $ancora = $indicadores[$configuracao['ancora']] ?? null;
        $fim = $ancora === null ? null : $this->ultimoMesDaAncora($series[$ancora->id] ?? [], $ids, $necessarios);

        if ($fim === null) {
            $avisos[] = 'Sem dados suficientes do indicador-âncora ('.$configuracao['ancora'].') para definir o mês de referência.';

            return [];
        }

        $meses = $this->meses($fim, (int) $configuracao['janela_meses']);

        // Pass 1: último dado vigente de cada indicador em cada mês, e a nota base (valor alto = nota alta).
        $vigentes = [];
        $notasBase = [];
        $emUso = [];

        foreach ($meses as $mes) {
            foreach ($componentes as $codigo => $componente) {
                $indicador = $indicadores[$codigo] ?? null;

                if ($indicador === null) {
                    continue;
                }

                $validade = $componente['validade_meses'] > 0
                    ? $componente['validade_meses']
                    : (int) $configuracao['validade_meses'][$indicador->periodicidade->value];

                $valores = [];

                foreach ($ids as $municipio) {
                    $vigente = $this->valorVigente($series[$indicador->id][$municipio] ?? [], $mes, $validade);

                    if ($vigente !== null) {
                        $valores[$municipio] = $indicador->valorAvaliado($vigente[1]);
                        $vigentes[$mes][$codigo][$municipio] = [$vigente[1], $vigente[0]];
                    }
                }

                if (count($valores) >= $necessarios) {
                    $notasBase[$mes][$codigo] = Normalizador::notas($valores);
                    $emUso[$codigo] = true;
                }
            }
        }

        // Pass 2: compõe os índices de cada município, depois classifica na matriz com os cortes do mês.
        $compositor = new CompositorDeIndice((float) $configuracao['confianca_minima']);
        $matriz = new MatrizIpf($configuracao['ipf']['quadrantes']);
        $efetividade = $configuracao['efetividade'];
        $linhas = [];

        foreach ($meses as $mes) {
            $resultados = [];

            foreach ($ids as $municipio) {
                foreach (['ina', 'idaps'] as $indice) {
                    $notas = [];

                    foreach ($configuracao[$indice]['pilares'] as $pilar) {
                        foreach ($pilar['componentes'] as $codigo => $componente) {
                            $base = $notasBase[$mes][$codigo][$municipio] ?? null;
                            $notas[$codigo] = $base === null ? null : ($componente['sentido'] === 'baixo' ? Normalizador::inverter($base) : $base);
                        }
                    }

                    $resultados[$municipio][$indice] = $compositor->compor($configuracao[$indice]['pilares'], $notas, $emUso) + ['notas' => $notas];
                }
            }

            $calculados = array_filter($resultados, fn (array $r): bool => $r['ina']['calculado'] && $r['idaps']['calculado']);
            $corteIna = MatrizIpf::corte(array_map(fn (array $r): float => $r['ina']['nota'], array_values($calculados)), $configuracao['ipf']['corte']);
            $corteIdaps = MatrizIpf::corte(array_map(fn (array $r): float => $r['idaps']['nota'], array_values($calculados)), $configuracao['ipf']['corte']);

            foreach ($resultados as $municipio => $r) {
                if (! $r['ina']['calculado'] && ! $r['idaps']['calculado']) {
                    continue;
                }

                $ambos = $r['ina']['calculado'] && $r['idaps']['calculado'];
                $estrutura = $r['idaps']['pilares'][$efetividade['pilar_estrutura']]['nota'] ?? null;
                $resultado = $r['idaps']['pilares'][$efetividade['pilar_resultado']]['nota'] ?? null;

                $linhas[] = [
                    'municipio_id' => $municipio,
                    'competencia' => $mes,
                    'metodologia_id' => $metodologia->id,
                    'ina' => $this->arredondar($r['ina']['nota'], 2),
                    'idaps' => $this->arredondar($r['idaps']['nota'], 2),
                    'idaps_estrutura' => $this->arredondar($estrutura, 2),
                    'idaps_resultado' => $this->arredondar($resultado, 2),
                    'ipf_quadrante' => $ambos ? $matriz->classificar($r['ina']['nota'], $r['idaps']['nota'], $corteIna, $corteIdaps)->value : null,
                    'ipf_pontuacao' => $ambos ? $this->arredondar(MatrizIpf::pontuacao($r['ina']['nota'], $r['idaps']['nota']), 2) : null,
                    'estrutura_sem_resultado' => MatrizIpf::estruturaSemResultado($estrutura, $resultado, $efetividade),
                    'confianca_ina' => $this->arredondar($r['ina']['confianca'], 3),
                    'confianca_idaps' => $this->arredondar($r['idaps']['confianca'], 3),
                    'detalhes' => json_encode([
                        'ina' => $this->detalhar($r['ina'], $r['ina']['notas'], $vigentes[$mes] ?? [], $municipio, $configuracao['ina']['pilares']),
                        'idaps' => $this->detalhar($r['idaps'], $r['idaps']['notas'], $vigentes[$mes] ?? [], $municipio, $configuracao['idaps']['pilares']),
                    ], JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return $linhas;
    }

    /**
     * Explica o número: nota de cada pilar e, por indicador, o valor usado, o mês dele e a nota obtida.
     *
     * @param  array<string, mixed>  $resultado
     * @param  array<string, float|null>  $notas
     * @param  array<string, array<int, array{0: float, 1: int}>>  $vigentes
     * @param  array<string, array{componentes: array<string, mixed>}>  $pilares
     * @return array<string, mixed>
     */
    private function detalhar(array $resultado, array $notas, array $vigentes, int $municipio, array $pilares): array
    {
        $componentes = [];

        foreach ($pilares as $pilar) {
            foreach (array_keys($pilar['componentes']) as $codigo) {
                $vigente = $vigentes[$codigo][$municipio] ?? null;

                if ($vigente !== null && ($notas[$codigo] ?? null) !== null) {
                    $componentes[$codigo] = ['valor' => round($vigente[0], 4), 'competencia' => $vigente[1], 'nota' => round($notas[$codigo], 1)];
                }
            }
        }

        return [
            'pilares' => array_map(fn (array $p): array => ['nota' => $this->arredondar($p['nota'], 1), 'completude' => round($p['completude'], 3)], $resultado['pilares']),
            'componentes' => $componentes,
            'faltantes' => $resultado['faltantes'],
        ];
    }

    /**
     * Último mês em que o indicador-âncora existe para municípios suficientes.
     *
     * @param  array<int, list<array{0: int, 1: float}>>  $porMunicipio
     * @param  list<int>  $ids
     */
    private function ultimoMesDaAncora(array $porMunicipio, array $ids, int $necessarios): ?int
    {
        $contagem = [];

        foreach ($ids as $municipio) {
            foreach ($porMunicipio[$municipio] ?? [] as [$competencia]) {
                $contagem[$competencia] = ($contagem[$competencia] ?? 0) + 1;
            }
        }

        krsort($contagem);

        foreach ($contagem as $competencia => $quantidade) {
            if ($quantidade >= $necessarios) {
                return (int) $competencia;
            }
        }

        return null;
    }

    /**
     * @return list<int> `quantidade` competências AAAAMM em ordem crescente, terminando em $fim
     */
    private function meses(int $fim, int $quantidade): array
    {
        $ultimo = self::indiceDoMes($fim);
        $meses = [];

        for ($i = $quantidade - 1; $i >= 0; $i--) {
            $posicao = $ultimo - $i;
            $meses[] = intdiv($posicao, 12) * 100 + $posicao % 12 + 1;
        }

        return $meses;
    }

    /**
     * Último valor conhecido até o mês de referência, se tiver no máximo $validade meses.
     *
     * @param  list<array{0: int, 1: float}>  $serie  em ordem crescente de competência
     * @return array{0: int, 1: float}|null [competência, valor]
     */
    private function valorVigente(array $serie, int $referencia, int $validade): ?array
    {
        for ($i = count($serie) - 1; $i >= 0; $i--) {
            if ($serie[$i][0] <= $referencia) {
                return self::indiceDoMes($referencia) - self::indiceDoMes($serie[$i][0]) <= $validade ? $serie[$i] : null;
            }
        }

        return null;
    }

    private static function indiceDoMes(int $competencia): int
    {
        return intdiv($competencia, 100) * 12 + $competencia % 100 - 1;
    }

    private function arredondar(?float $valor, int $casas): ?float
    {
        return $valor === null ? null : round($valor, $casas);
    }

    /**
     * Troca o resultado anterior pelo novo, de uma vez: se algo falhar, o anterior permanece.
     *
     * @param  list<array<string, mixed>>  $linhas
     */
    private function gravar(array $linhas): void
    {
        $derivados = Indicador::query()->ativo()->whereIn('codigo', array_values(self::INDICADORES_DERIVADOS))->pluck('id', 'codigo');
        $competenciasPorIndicador = [];

        DB::transaction(function () use ($linhas, $derivados, &$competenciasPorIndicador): void {
            IndiceMunicipio::query()->delete();

            foreach (array_chunk($linhas, self::TAMANHO_DO_LOTE) as $lote) {
                IndiceMunicipio::query()->insert($lote);
            }

            if ($derivados->isEmpty()) {
                return;
            }

            ValorIndicador::query()->whereIn('indicador_id', $derivados->values()->all())->delete();
            Benchmark::query()->whereIn('indicador_id', $derivados->values()->all())->delete();

            $valores = [];

            foreach ($linhas as $linha) {
                foreach (self::INDICADORES_DERIVADOS as $coluna => $codigo) {
                    $indicadorId = $derivados[$codigo] ?? null;

                    if ($indicadorId === null || $linha[$coluna] === null) {
                        continue;
                    }

                    $valores[] = ['municipio_id' => $linha['municipio_id'], 'indicador_id' => $indicadorId, 'competencia' => $linha['competencia'], 'valor' => $linha[$coluna]];
                    $competenciasPorIndicador[$indicadorId][$linha['competencia']] = $linha['competencia'];
                }
            }

            foreach (array_chunk($valores, 1000) as $lote) {
                ValorIndicador::gravarEmLote($lote);
            }
        });

        if ($competenciasPorIndicador !== []) {
            $this->benchmarks->calcular(array_map(fn (array $competencias): array => array_values($competencias), $competenciasPorIndicador));
        }

        VersaoDosDados::renovar();
    }
}
