<?php

namespace App\Widgets;

use App\Domain\Indicators\ConsultaDeIndicadores;
use App\Domain\Narrative\Analista;
use App\Domain\Narrative\AnalistaDaMatriz;
use App\Domain\Narrative\AnalistaDoMapa;
use App\Domain\Scoring\ConsultaDeIndices;
use App\Enums\EscopoBenchmark;
use App\Enums\Polaridade;
use App\Enums\QuadranteIpf;
use App\Enums\TipoDeVisual;
use App\Models\Benchmark;
use App\Models\Indicador;
use App\Models\IndiceMunicipio;
use App\Models\Municipio;
use App\Support\Competencia;
use App\Support\Formatador;
use App\Support\VersaoDosDados;
use Illuminate\Support\Facades\Cache;

/**
 * Monta, para um visual, tudo o que a tela precisa: valores, dados do gráfico, tabela alternativa, legenda e a
 * análise em texto. O resultado é cacheado por (visual, município, janela, escopo, versão dos dados): quando uma
 * integração grava dados novos, a versão muda e o cache se renova sozinho.
 */
class ConstrutorDeVisual
{
    public const ESCOPO_REGIAO = 'regiao';

    public const ESCOPO_ESTADO = 'estado';

    private const MINUTOS_DE_CACHE = 360;

    /** No ranking do estado, quantos municípios aparecem de cada lado do município em foco. */
    private const VIZINHOS_NO_ESTADO = 7;

    /**
     * Aumente sempre que mudar o formato do visual ou as regras/textos da análise: invalida o cache antigo
     * (a versão dos dados só muda quando chegam dados novos, não quando o código muda).
     */
    private const VERSAO_DAS_REGRAS = 3;

    public function __construct(
        private readonly ConsultaDeIndicadores $consulta,
        private readonly ConsultaDeIndices $indices,
        private readonly Analista $analista,
        private readonly AnalistaDoMapa $analistaDoMapa,
        private readonly AnalistaDaMatriz $analistaDaMatriz,
        private readonly ConstrutorDoMapa $mapa,
        private readonly ConstrutorDaMatriz $matriz,
    ) {}

    /**
     * @param  string  $escopo  só vale para o ranking: a região de saúde ou o estado
     * @return array<string, mixed>
     */
    public function construir(TipoDeVisual $tipo, Indicador $indicador, Municipio $municipio, int $meses, string $escopo = self::ESCOPO_REGIAO): array
    {
        $escopo = $escopo === self::ESCOPO_ESTADO ? self::ESCOPO_ESTADO : self::ESCOPO_REGIAO;
        $chave = sprintf('aps.visual.r%d.%s.%s.%d.%d.%s.v%d', self::VERSAO_DAS_REGRAS, $tipo->value, $indicador->codigo, $municipio->id, $meses, $escopo, VersaoDosDados::atual());

        return Cache::remember($chave, now()->addMinutes(self::MINUTOS_DE_CACHE), fn (): array => $this->montar($tipo, $indicador, $municipio, $meses, $escopo));
    }

    /**
     * @return array<string, mixed>
     */
    private function montar(TipoDeVisual $tipo, Indicador $indicador, Municipio $municipio, int $meses, string $escopo): array
    {
        $base = [
            'tipo' => $tipo->value,
            'titulo' => match ($tipo) {
                TipoDeVisual::Destaque => $indicador->nome,
                TipoDeVisual::Evolucao => 'Evolução: '.$indicador->nome,
                TipoDeVisual::Ranking => 'Ranking: '.$indicador->nome,
                TipoDeVisual::Mapa => 'Mapa: '.$indicador->nome,
                TipoDeVisual::Matriz => 'Matriz de prioridade: necessidade × desempenho',
            },
            'indicador' => [
                'codigo' => $indicador->codigo,
                'nome' => $indicador->nome,
                'o_que_e' => $indicador->o_que_e,
                'como_calcula' => $indicador->como_calcula,
                'para_que_serve' => $indicador->para_que_serve,
                'como_interpretar' => $indicador->como_interpretar,
                'fonte' => $indicador->fonteRotulo(),
                'polaridade' => $indicador->polaridade->rotulo(),
            ],
            'competencia' => null,
            'sem_dado' => false,
            'kpi' => null,
            'grafico' => null,
            'tabela' => null,
            'legenda' => null,
            'escopo' => $tipo === TipoDeVisual::Ranking ? $escopo : null,
        ];

        $dados = match ($tipo) {
            TipoDeVisual::Destaque => $this->destaque($indicador, $municipio),
            TipoDeVisual::Evolucao => $this->evolucao($indicador, $municipio, $meses),
            TipoDeVisual::Ranking => $this->ranking($indicador, $municipio, $escopo),
            TipoDeVisual::Mapa => $this->mapa($indicador, $municipio),
            TipoDeVisual::Matriz => $this->matriz($indicador, $municipio),
        };

        return array_merge($base, $dados);
    }

    /**
     * @return array<string, mixed>
     */
    private function destaque(Indicador $indicador, Municipio $municipio): array
    {
        $ultimo = $this->consulta->ultimo($municipio->id, $indicador);

        if ($ultimo === null) {
            return $this->semDados($indicador, $municipio);
        }

        $competencia = $ultimo['competencia'];
        $regiao = $this->benchmarkDaRegiao($indicador, $competencia, $municipio);
        $estado = $this->consulta->benchmark($indicador, $competencia, EscopoBenchmark::Uf, $municipio->codigo_uf);
        $anterior = $this->consulta->umAnoAntes($municipio->id, $indicador, $competencia);
        $posicao = $this->consulta->posicaoNoEstado($indicador, $competencia, $municipio, $ultimo['valor']);

        $analise = $this->analista->destaque($municipio, $indicador, [
            'competencia' => $competencia,
            'valor' => $ultimo['valor'],
            'anterior' => $anterior,
            'mediana_regiao' => $regiao?->mediana,
            'quantidade_regiao' => $regiao?->quantidade,
            'posicao' => $posicao,
        ]);

        $tendencia = $this->analista->tendencia($anterior['valor'] ?? null, $ultimo['valor'], $indicador);

        $comparativos = [];

        if ($regiao !== null && $regiao->quantidade >= 3) {
            $comparativos[] = ['rotulo' => 'Mediana da região de saúde', 'valor' => Formatador::valor($regiao->mediana, $indicador)];
        }

        if ($estado !== null) {
            $comparativos[] = ['rotulo' => 'Mediana do estado', 'valor' => Formatador::valor($estado->mediana, $indicador)];
        }

        return [
            'competencia' => $indicador->rotuloDaCompetencia($competencia),
            'analise' => $analise->toArray(),
            'kpi' => [
                'valor' => Formatador::valor($ultimo['valor'], $indicador),
                'numero' => $this->numeroDoDestaque($ultimo['valor'], $indicador),
                'unidade' => in_array($indicador->unidade, ['%', 'R$'], true) ? '' : $indicador->unidade,
                'variacao' => $anterior === null ? null : [
                    'texto' => Formatador::variacao($anterior['valor'], $ultimo['valor'], $indicador),
                    'direcao' => $tendencia['direcao'],
                    'sentido' => $tendencia['sentido'],
                    'referencia' => $indicador->rotuloDaCompetencia($anterior['competencia']),
                ],
                'comparativos' => $comparativos,
                'faixa' => $this->faixaNaRegiao($indicador, $municipio, $ultimo['valor'], $competencia, $regiao),
                'sparkline' => array_map(
                    fn (array $ponto): array => ['rotulo' => $indicador->rotuloDaCompetencia($ponto['competencia']), 'texto' => Formatador::valor($ponto['valor'], $indicador), 'valor' => $ponto['valor']],
                    $this->consulta->serie($municipio->id, $indicador, 12),
                ),
            ],
        ];
    }

    /**
     * Onde o município está entre o menor e o maior valor da região, com a mediana marcada. Só existe quando há
     * municípios suficientes na região para a comparação fazer sentido.
     *
     * @return array<string, mixed>|null
     */
    private function faixaNaRegiao(Indicador $indicador, Municipio $municipio, float $valor, int $competencia, ?Benchmark $regiao): ?array
    {
        if ($regiao === null || $regiao->quantidade < 3 || $regiao->maximo <= $regiao->minimo) {
            return null;
        }

        $ponto = fn (float $v): float => round(max(0.0, min(100.0, ($v - $regiao->minimo) / ($regiao->maximo - $regiao->minimo) * 100)), 1);
        $posicao = null;

        if ($indicador->polaridade !== Polaridade::Neutra) {
            $indice = array_search($municipio->id, array_column($this->consulta->ranking($indicador, $competencia, $municipio->regiao_saude_codigo), 'municipio_id'), true);
            $posicao = $indice === false ? null : $indice + 1;
        }

        return [
            'minimo' => Formatador::valor($regiao->minimo, $indicador),
            'maximo' => Formatador::valor($regiao->maximo, $indicador),
            'mediana' => Formatador::valor($regiao->mediana, $indicador),
            'valor' => Formatador::valor($valor, $indicador),
            'x_valor' => $ponto($valor),
            'x_mediana' => $ponto($regiao->mediana),
            'posicao' => $posicao,
            'total' => $regiao->quantidade,
            'melhor_e_maior' => $indicador->polaridade === Polaridade::MaiorMelhor,
            'melhor_e_menor' => $indicador->polaridade === Polaridade::MenorMelhor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evolucao(Indicador $indicador, Municipio $municipio, int $meses): array
    {
        $serie = $this->consulta->serie($municipio->id, $indicador, $meses);

        if ($serie === []) {
            return $this->semDados($indicador, $municipio);
        }

        $competencias = array_column($serie, 'competencia');
        $medianasRegiao = $municipio->regiao_saude_codigo === null ? [] : $this->consulta->medianas($indicador, EscopoBenchmark::RegiaoSaude, $municipio->regiao_saude_codigo, $competencias);
        $medianasEstado = $this->consulta->medianas($indicador, EscopoBenchmark::Uf, $municipio->codigo_uf, $competencias);
        $ultima = $competencias[array_key_last($competencias)];
        $regiaoNoFim = $this->benchmarkDaRegiao($indicador, $ultima, $municipio);

        $analise = $this->analista->evolucao($municipio, $indicador, $serie, $medianasRegiao, $regiaoNoFim?->quantidade);

        $series = [['nome' => $municipio->nome, 'dados' => array_column($serie, 'valor'), 'papel' => 'municipio']];

        if ($medianasRegiao !== []) {
            $series[] = ['nome' => 'Mediana da região de saúde', 'dados' => array_map(fn (int $c): ?float => $medianasRegiao[$c] ?? null, $competencias), 'papel' => 'regiao'];
        }

        if ($medianasEstado !== []) {
            $series[] = ['nome' => 'Mediana do estado', 'dados' => array_map(fn (int $c): ?float => $medianasEstado[$c] ?? null, $competencias), 'papel' => 'estado'];
        }

        $rotulos = array_map(fn (int $c): string => $indicador->rotuloDaCompetencia($c), $competencias);

        return [
            'competencia' => $indicador->rotuloDaCompetencia($ultima),
            'analise' => $analise->toArray(),
            'grafico' => ['modo' => 'evolucao', 'categorias' => $rotulos, 'series' => $series, 'teto' => $indicador->teto, 'indicador' => $indicador->nome] + $this->formato($indicador),
            'tabela' => [
                'colunas' => ['Período', ...array_column($series, 'nome')],
                'linhas' => array_map(
                    fn (int $i): array => [$rotulos[$i], ...array_map(fn (array $s): string => $s['dados'][$i] === null ? '—' : Formatador::valor($s['dados'][$i], $indicador), $series)],
                    array_keys($rotulos),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ranking(Indicador $indicador, Municipio $municipio, string $escopo): array
    {
        $ultimo = $this->consulta->ultimo($municipio->id, $indicador);

        if ($ultimo === null || $municipio->regiao_saude_codigo === null) {
            return $this->semDados($indicador, $municipio);
        }

        $competencia = $ultimo['competencia'];
        $daRegiao = $this->consulta->ranking($indicador, $competencia, $municipio->regiao_saude_codigo);
        $regiao = $this->benchmarkDaRegiao($indicador, $competencia, $municipio);
        $analise = $this->analista->ranking($municipio, $indicador, $competencia, $daRegiao, $regiao?->mediana);
        $paragrafos = $analise->paragrafos;

        $lista = $daRegiao;
        $primeiraPosicao = 1;
        $mediana = $regiao !== null && $regiao->quantidade >= 3 ? $regiao->mediana : null;
        $rotuloDaMediana = 'Mediana da região';
        $total = count($daRegiao);

        if ($escopo === self::ESCOPO_ESTADO) {
            $doEstado = $this->consulta->rankingDoEstado($indicador, $competencia, $municipio->codigo_uf);
            $total = count($doEstado);
            $meu = array_search($municipio->id, array_column($doEstado, 'municipio_id'), true);
            $inicio = $meu === false ? 0 : max(0, min($meu - self::VIZINHOS_NO_ESTADO, $total - (2 * self::VIZINHOS_NO_ESTADO + 1)));
            $lista = array_slice($doEstado, $inicio, 2 * self::VIZINHOS_NO_ESTADO + 1);
            $primeiraPosicao = $inicio + 1;
            $mediana = $this->consulta->benchmark($indicador, $competencia, EscopoBenchmark::Uf, $municipio->codigo_uf)?->mediana;
            $rotuloDaMediana = 'Mediana do estado';

            if ($meu !== false && $indicador->polaridade !== Polaridade::Neutra) {
                $paragrafos[] = sprintf('No estado, %s ocupa a %s posição entre %d municípios com dado (a 1ª é a melhor situação). O gráfico mostra os %d municípios mais próximos dele nessa ordem.', $municipio->nome, Formatador::ordinal($meu + 1), $total, count($lista));
            }
        }

        return [
            'competencia' => $indicador->rotuloDaCompetencia($competencia),
            'analise' => ['tom' => $analise->tom, 'paragrafos' => $paragrafos],
            'grafico' => [
                'modo' => 'ranking',
                'categorias' => array_column($lista, 'nome'),
                'valores' => array_column($lista, 'valor'),
                'ids' => array_column($lista, 'municipio_id'),
                'posicoes' => range($primeiraPosicao, $primeiraPosicao + count($lista) - 1),
                'total' => $total,
                'destaque' => (int) array_search($municipio->id, array_column($lista, 'municipio_id'), true),
                'mediana' => $mediana,
                'rotulo_da_mediana' => $rotuloDaMediana,
                'melhor_e_maior' => $indicador->polaridade === Polaridade::MaiorMelhor,
                'melhor_e_menor' => $indicador->polaridade === Polaridade::MenorMelhor,
            ] + $this->formato($indicador),
            'tabela' => [
                'colunas' => ['Posição', 'Município', 'Valor'],
                'linhas' => array_map(
                    fn (array $linha, int $i): array => [Formatador::ordinal($primeiraPosicao + $i), $linha['nome'], Formatador::valor($linha['valor'], $indicador)],
                    $lista,
                    array_keys($lista),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapa(Indicador $indicador, Municipio $municipio): array
    {
        $competencia = $this->consulta->competenciaDoMapa($indicador, $municipio->codigo_uf);

        if ($competencia === null) {
            return $this->semDados($indicador, $municipio);
        }

        $valores = $this->consulta->valoresDaCompetencia($indicador, $competencia, $municipio->codigo_uf);
        $municipios = Municipio::query()->ativo()->where('codigo_uf', $municipio->codigo_uf)->get(['id', 'nome', 'regiao_saude_nome'])->keyBy('id');
        $rotulo = $indicador->rotuloDaCompetencia($competencia);
        $grafico = $this->mapa->construir($indicador, $rotulo, $valores, $municipios, $municipio->id, $municipio->codigo_uf);
        $tabela = $this->mapa->tabela($indicador, $valores, $municipios);

        return [
            'competencia' => $rotulo,
            'analise' => $this->analistaDoMapa->analisar($indicador, $rotulo, $valores, $municipios->pluck('nome', 'id')->all(), $municipio->id, $municipios->count())->toArray(),
            'grafico' => $grafico,
            'tabela' => ['colunas' => $tabela['colunas'], 'linhas' => $tabela['linhas']],
            'legenda' => ['tipo' => 'faixas', 'faixas' => $grafico['faixas'], 'sem_dado' => $grafico['sem_dado']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function matriz(Indicador $indicador, Municipio $municipio): array
    {
        $competencias = $this->indices->competencias($municipio->codigo_uf);

        if ($competencias === []) {
            return $this->semDados($indicador, $municipio);
        }

        $competencia = $competencias[0];
        $linhas = $this->indices->doMes($municipio->codigo_uf, $competencia);
        $porPrioridade = $this->indices->porPrioridade($linhas);

        if ($porPrioridade->isEmpty()) {
            return $this->semDados($indicador, $municipio);
        }

        $resumo = $this->indices->resumoDosQuadrantes($linhas);
        $posicao = $porPrioridade->search(fn (IndiceMunicipio $linha): bool => $linha->municipio_id === $municipio->id);
        $anterior = $this->indices->serie($municipio->id)->first(fn (IndiceMunicipio $linha): bool => $linha->competencia < $competencia && $linha->competencia >= $competencia - 100);

        $analise = $this->analistaDaMatriz->analisar(
            $municipio->nome,
            $linhas->get($municipio->id),
            $anterior,
            $resumo,
            $posicao === false ? null : ['posicao' => $posicao + 1, 'total' => $porPrioridade->count()],
            $competencia,
        );

        return [
            'competencia' => Competencia::rotulo($competencia),
            'analise' => $analise->toArray(),
            'grafico' => $this->matriz->construir($linhas, $this->indices->cortes($linhas), $municipio->id),
            'tabela' => [
                'colunas' => ['Posição', 'Município', 'INA', 'IDAPS', 'Quadrante'],
                'linhas' => $porPrioridade->map(fn (IndiceMunicipio $linha, int $i): array => [
                    Formatador::ordinal($i + 1),
                    $linha->municipio->nome,
                    Formatador::numero($linha->ina, 0),
                    Formatador::numero($linha->idaps, 0),
                    $linha->ipf_quadrante->rotulo(),
                ])->all(),
            ],
            'legenda' => [
                'tipo' => 'quadrantes',
                'itens' => array_map(fn (QuadranteIpf $q): array => ['quadrante' => $q->value, 'rotulo' => $q->rotulo(), 'quantidade' => $resumo[$q->value]], QuadranteIpf::cases()),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function semDados(Indicador $indicador, Municipio $municipio): array
    {
        return [
            'sem_dado' => true,
            'analise' => $this->analista->semDados($municipio, $indicador)->toArray(),
        ];
    }

    private function benchmarkDaRegiao(Indicador $indicador, int $competencia, Municipio $municipio): ?Benchmark
    {
        return $municipio->regiao_saude_codigo === null
            ? null
            : $this->consulta->benchmark($indicador, $competencia, EscopoBenchmark::RegiaoSaude, $municipio->regiao_saude_codigo);
    }

    private function numeroDoDestaque(float $valor, Indicador $indicador): string
    {
        $numero = Formatador::numero($valor, $indicador->casas_decimais);

        return match ($indicador->unidade) {
            '%' => $numero.'%',
            'R$' => 'R$ '.$numero,
            default => $numero,
        };
    }

    /**
     * Como o navegador deve escrever os números do gráfico (o formato PT-BR é aplicado no cliente).
     *
     * @return array{casas: int, prefixo: string, sufixo: string}
     */
    private function formato(Indicador $indicador): array
    {
        return [
            'casas' => $indicador->casas_decimais,
            'prefixo' => $indicador->unidade === 'R$' ? 'R$ ' : '',
            'sufixo' => match ($indicador->unidade) {
                '%' => '%',
                'R$' => '',
                default => ' '.$indicador->unidade,
            },
        ];
    }
}
