<?php

namespace App\Domain\Narrative;

use App\Enums\QuadranteIpf;
use App\Models\IndiceMunicipio;
use App\Support\Competencia;
use App\Support\Formatador;

/**
 * "Análise do cenário atual" da matriz INA × IDAPS: o que os índices dizem sobre o município escolhido,
 * em linguagem simples. Regras determinísticas, sem IA externa.
 */
class AnalistaDaMatriz
{
    /** Variação do IDAPS (pontos) até a qual o município é considerado estável. */
    private const TOLERANCIA_EM_PONTOS = 1.0;

    /** Abaixo desta confiança o texto avisa que o índice usa só parte da metodologia. */
    private const CONFIANCA_PLENA = 0.9;

    /**
     * @param  array<int, int>  $resumo  quadrante (valor do enum) => quantidade de municípios
     * @param  array{posicao: int, total: int}|null  $posicaoNaPrioridade
     */
    public function analisar(string $nome, ?IndiceMunicipio $linha, ?IndiceMunicipio $anterior, array $resumo, ?array $posicaoNaPrioridade, int $competencia): Analise
    {
        $mes = Competencia::rotulo($competencia);

        if ($linha === null || ($linha->ina === null && $linha->idaps === null)) {
            return new Analise(Analise::SEM_DADO, ["Não há índices calculados para {$nome} em {$mes}. Isso acontece quando faltam dados de indicadores obrigatórios na metodologia; veja a página Metodologia."]);
        }

        if ($linha->ipf_quadrante === null) {
            return $this->semMatriz($nome, $linha, $anterior, $mes);
        }

        $quadrante = $linha->ipf_quadrante;
        $paragrafos = [
            sprintf(
                'Em %s, a necessidade da população (INA) é %s e o desempenho da Atenção Primária (IDAPS) é %s, em uma escala de 0 a 100 que compara o município com os demais do estado (%s).',
                $nome,
                Formatador::numero($linha->ina, 0),
                Formatador::numero($linha->idaps, 0),
                $mes,
            ),
            sprintf('Isso coloca %s em “%s”: %s', $nome, $quadrante->rotulo(), $quadrante->descricao()),
        ];

        if ($posicaoNaPrioridade !== null) {
            $paragrafos[] = sprintf(
                'Na prioridade de apoio, %s ocupa a %s posição entre %d municípios (a 1ª é a de maior prioridade).',
                $nome,
                Formatador::ordinal($posicaoNaPrioridade['posicao']),
                $posicaoNaPrioridade['total'],
            );
        }

        if ($linha->estrutura_sem_resultado) {
            $paragrafos[] = sprintf(
                'Ponto de atenção: a estrutura e a cobertura (nota %s) estão entre as mais fortes do estado, mas os resultados em saúde (nota %s) ficam abaixo. Vale investigar por que a estrutura ainda não se traduz em resultado.',
                Formatador::numero($linha->idaps_estrutura, 0),
                Formatador::numero($linha->idaps_resultado, 0),
            );
        }

        if ($frase = $this->tendencia($linha, $anterior)) {
            $paragrafos[] = $frase;
        }

        $paragrafos[] = $this->resumoDoEstado($resumo, $quadrante);

        if ($aviso = $this->avisoDeConfianca($linha)) {
            $paragrafos[] = $aviso;
        }

        return new Analise($this->tom($quadrante, $linha->estrutura_sem_resultado), $paragrafos);
    }

    private function semMatriz(string $nome, IndiceMunicipio $linha, ?IndiceMunicipio $anterior, string $mes): Analise
    {
        $paragrafos = [];

        if ($linha->idaps !== null) {
            $paragrafos[] = sprintf('Em %s, o desempenho da Atenção Primária (IDAPS) é %s, em uma escala de 0 a 100 (%s).', $nome, Formatador::numero($linha->idaps, 0), $mes);
            $paragrafos[] = 'O município ainda não aparece na matriz porque a necessidade da população (INA) não pôde ser calculada: faltam dados de vulnerabilidade social (como Bolsa Família e BPC) para os municípios do estado.';

            if ($frase = $this->tendencia($linha, $anterior)) {
                $paragrafos[] = $frase;
            }
        } else {
            $paragrafos[] = sprintf('Em %s, a necessidade da população (INA) é %s (%s), mas o desempenho da Atenção Primária (IDAPS) não pôde ser calculado por falta de dados.', $nome, Formatador::numero($linha->ina, 0), $mes);
        }

        if ($aviso = $this->avisoDeConfianca($linha)) {
            $paragrafos[] = $aviso;
        }

        return new Analise(Analise::INFORMATIVO, $paragrafos);
    }

    private function tendencia(IndiceMunicipio $linha, ?IndiceMunicipio $anterior): ?string
    {
        if ($anterior === null || $anterior->idaps === null || $linha->idaps === null || $anterior->competencia === $linha->competencia) {
            return null;
        }

        $diferenca = $linha->idaps - $anterior->idaps;
        $desde = Competencia::rotulo($anterior->competencia);

        if (abs($diferenca) < self::TOLERANCIA_EM_PONTOS) {
            return "Desde {$desde}, o IDAPS ficou estável. Como a nota compara o município com os demais, isso significa que ele manteve a posição relativa.";
        }

        return sprintf(
            'Desde %s, o IDAPS %s %s pontos. Como a nota compara o município com os demais, isso mostra se ele melhorou mais ou menos que o resto do estado.',
            $desde,
            $diferenca > 0 ? 'subiu' : 'caiu',
            Formatador::numero(abs($diferenca), 0),
        );
    }

    /**
     * @param  array<int, int>  $resumo
     */
    private function resumoDoEstado(array $resumo, QuadranteIpf $doMunicipio): string
    {
        $total = array_sum($resumo);
        $nesteGrupo = $resumo[$doMunicipio->value];

        return sprintf(
            'No estado, %d de %d municípios com os dois índices (%s%%) estão em “%s”.',
            $nesteGrupo,
            $total,
            Formatador::numero($total > 0 ? $nesteGrupo / $total * 100 : 0, 0),
            $doMunicipio->rotulo(),
        );
    }

    private function avisoDeConfianca(IndiceMunicipio $linha): ?string
    {
        $menor = min(array_filter([$linha->confianca_ina, $linha->confianca_idaps], fn (?float $c): bool => $c !== null) ?: [1.0]);

        if ($menor >= self::CONFIANCA_PLENA) {
            return null;
        }

        return sprintf('Atenção: o cálculo usou cerca de %s%% da metodologia (alguns indicadores estão sem dado para este município). Leia o resultado com cautela.', Formatador::numero($menor * 100, 0));
    }

    private function tom(QuadranteIpf $quadrante, bool $estruturaSemResultado): string
    {
        if ($estruturaSemResultado) {
            return Analise::ATENCAO;
        }

        return match ($quadrante) {
            QuadranteIpf::PrioridadeMaxima => Analise::ATENCAO,
            QuadranteIpf::EstruturaConsolidada => Analise::FAVORAVEL,
            default => Analise::INTERMEDIARIO,
        };
    }
}
