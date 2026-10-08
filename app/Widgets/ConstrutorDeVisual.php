<?php

namespace App\Widgets;

use App\Domain\Indicators\ConsultaDeIndicadores;
use App\Domain\Narrative\Analista;
use App\Enums\EscopoBenchmark;
use App\Enums\TipoDeVisual;
use App\Models\Benchmark;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Support\Formatador;
use App\Support\VersaoDosDados;
use Illuminate\Support\Facades\Cache;

/**
 * Monta, para um visual, tudo o que a tela precisa: valores, dados do gráfico, tabela alternativa e a
 * análise em texto. O resultado é cacheado por (visual, município, janela, versão dos dados): quando uma
 * integração grava dados novos, a versão muda e o cache se renova sozinho.
 */
class ConstrutorDeVisual
{
    private const MINUTOS_DE_CACHE = 360;

    /**
     * Aumente sempre que mudar o formato do visual ou as regras/textos da análise: invalida o cache antigo
     * (a versão dos dados só muda quando chegam dados novos, não quando o código muda).
     */
    private const VERSAO_DAS_REGRAS = 2;

    public function __construct(
        private readonly ConsultaDeIndicadores $consulta,
        private readonly Analista $analista,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function construir(TipoDeVisual $tipo, Indicador $indicador, Municipio $municipio, int $meses): array
    {
        $chave = sprintf('aps.visual.r%d.%s.%s.%d.%d.v%d', self::VERSAO_DAS_REGRAS, $tipo->value, $indicador->codigo, $municipio->id, $meses, VersaoDosDados::atual());

        return Cache::remember($chave, now()->addMinutes(self::MINUTOS_DE_CACHE), fn (): array => $this->montar($tipo, $indicador, $municipio, $meses));
    }

    /**
     * @return array<string, mixed>
     */
    private function montar(TipoDeVisual $tipo, Indicador $indicador, Municipio $municipio, int $meses): array
    {
        $base = [
            'tipo' => $tipo->value,
            'titulo' => match ($tipo) {
                TipoDeVisual::Destaque => $indicador->nome,
                TipoDeVisual::Evolucao => 'Evolução: '.$indicador->nome,
                TipoDeVisual::Ranking => 'Ranking: '.$indicador->nome,
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
        ];

        $dados = match ($tipo) {
            TipoDeVisual::Destaque => $this->destaque($indicador, $municipio),
            TipoDeVisual::Evolucao => $this->evolucao($indicador, $municipio, $meses),
            TipoDeVisual::Ranking => $this->ranking($indicador, $municipio),
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
                'sparkline' => array_column($this->consulta->serie($municipio->id, $indicador, 12), 'valor'),
            ],
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
            'grafico' => ['modo' => 'evolucao', 'categorias' => $rotulos, 'series' => $series] + $this->formato($indicador),
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
    private function ranking(Indicador $indicador, Municipio $municipio): array
    {
        $ultimo = $this->consulta->ultimo($municipio->id, $indicador);

        if ($ultimo === null || $municipio->regiao_saude_codigo === null) {
            return $this->semDados($indicador, $municipio);
        }

        $competencia = $ultimo['competencia'];
        $ranking = $this->consulta->ranking($indicador, $competencia, $municipio->regiao_saude_codigo);
        $regiao = $this->benchmarkDaRegiao($indicador, $competencia, $municipio);
        $analise = $this->analista->ranking($municipio, $indicador, $competencia, $ranking, $regiao?->mediana);

        return [
            'competencia' => $indicador->rotuloDaCompetencia($competencia),
            'analise' => $analise->toArray(),
            'grafico' => [
                'modo' => 'ranking',
                'categorias' => array_column($ranking, 'nome'),
                'valores' => array_column($ranking, 'valor'),
                'destaque' => (int) array_search($municipio->id, array_column($ranking, 'municipio_id'), true),
                'mediana' => $regiao !== null && $regiao->quantidade >= 3 ? $regiao->mediana : null,
            ] + $this->formato($indicador),
            'tabela' => [
                'colunas' => ['Posição', 'Município', 'Valor'],
                'linhas' => array_map(
                    fn (array $linha, int $i): array => [Formatador::ordinal($i + 1), $linha['nome'], Formatador::valor($linha['valor'], $indicador)],
                    $ranking,
                    array_keys($ranking),
                ),
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
