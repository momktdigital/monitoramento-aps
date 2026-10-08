<?php

namespace App\Domain\Narrative;

use App\Enums\Polaridade;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Support\Formatador;

/**
 * Escreve a "Análise do cenário atual" de cada visual com regras determinísticas (sem IA externa):
 * compara com a mediana da região, mede a tendência e a posição, e só emite juízo de valor
 * (melhor/pior) quando o indicador tem sentido bom ou ruim definido.
 *
 * Indicadores com "teto" (ex.: coberturas de 100%) tratam tudo a partir do teto como um único patamar:
 * 112% e 126% de cobertura significam o mesmo (todos têm equipe) e não são comparados como melhor/pior.
 */
class Analista
{
    /** Diferença relativa até a qual dois valores são considerados "praticamente iguais". */
    private const TOLERANCIA_RELATIVA = 0.03;

    /** Em indicadores percentuais, variações menores que isso (pontos percentuais) são estabilidade. */
    private const TOLERANCIA_EM_PONTOS = 0.1;

    /** Quantidade mínima de municípios com dado para a mediana regional ser citada. */
    private const MINIMO_NA_REGIAO = 3;

    /**
     * @param  array{competencia: int, valor: float, anterior: array{competencia: int, valor: float}|null, mediana_regiao: float|null, quantidade_regiao: int|null, posicao: array{posicao: int, total: int}|null}  $dados
     */
    public function destaque(Municipio $municipio, Indicador $indicador, array $dados): Analise
    {
        $valor = $dados['valor'];

        $primeira = sprintf(
            'Em %s, o valor atual de “%s” é %s (%s).',
            $municipio->nome,
            $indicador->nome,
            Formatador::valor($valor, $indicador),
            $indicador->rotuloDaCompetencia($dados['competencia']),
        );

        if ($indicador->estaNoTeto($valor)) {
            $primeira .= sprintf(' Esse valor já está no patamar pleno (a partir de %s).', $this->teto($indicador));
        }

        $paragrafos = [$primeira];

        $comparacao = $this->compararComRegiao($valor, $dados['mediana_regiao'], $dados['quantidade_regiao'], $indicador);
        $tendencia = $this->tendencia($dados['anterior']['valor'] ?? null, $valor, $indicador);

        if ($comparacao['frase'] !== null) {
            $paragrafos[] = $comparacao['frase'];
        }

        if ($dados['anterior'] !== null) {
            $paragrafos[] = $this->frasePorTendencia($dados['anterior'], $valor, $indicador, $tendencia);
        }

        if ($dados['posicao'] !== null && $indicador->polaridade !== Polaridade::Neutra) {
            $paragrafos[] = sprintf(
                'Entre os %d municípios do estado com dado, %s ocupa a %s posição (a 1ª é a melhor situação).',
                $dados['posicao']['total'],
                $municipio->nome,
                Formatador::ordinal($dados['posicao']['posicao']),
            );
        }

        return new Analise($this->tomPor($indicador, $valor, $comparacao['sentido'], $tendencia['sentido']), $paragrafos);
    }

    /**
     * @param  list<array{competencia: int, valor: float}>  $serie
     * @param  array<int, float>  $medianasDaRegiao  competência => mediana
     */
    public function evolucao(Municipio $municipio, Indicador $indicador, array $serie, array $medianasDaRegiao, ?int $quantidadeNaRegiao): Analise
    {
        $primeiro = $serie[0];
        $ultimo = $serie[array_key_last($serie)];

        if (count($serie) === 1) {
            return new Analise(Analise::INFORMATIVO, [
                sprintf(
                    'Há apenas um dado de “%s” para %s: %s (%s).',
                    $indicador->nome,
                    $municipio->nome,
                    Formatador::valor($ultimo['valor'], $indicador),
                    $indicador->rotuloDaCompetencia($ultimo['competencia']),
                ),
                'A linha do tempo se forma conforme novas atualizações de dados são carregadas.',
            ]);
        }

        $tendencia = $this->tendencia($primeiro['valor'], $ultimo['valor'], $indicador);
        $paragrafos = [sprintf(
            'Entre %s e %s, em %s, o valor de “%s” %s',
            $indicador->rotuloDaCompetencia($primeiro['competencia']),
            $indicador->rotuloDaCompetencia($ultimo['competencia']),
            $municipio->nome,
            $indicador->nome,
            $this->verboDaTendencia($tendencia, $primeiro['valor'], $ultimo['valor'], $indicador),
        )];

        if (count($serie) >= 4) {
            $valores = array_column($serie, 'valor');
            $maximo = $serie[array_search(max($valores), $valores, true)];
            $minimo = $serie[array_search(min($valores), $valores, true)];

            if ($maximo['valor'] !== $minimo['valor']) {
                $paragrafos[] = sprintf(
                    'O maior valor do período foi %s (%s) e o menor, %s (%s).',
                    Formatador::valor($maximo['valor'], $indicador),
                    $indicador->rotuloDaCompetencia($maximo['competencia']),
                    Formatador::valor($minimo['valor'], $indicador),
                    $indicador->rotuloDaCompetencia($minimo['competencia']),
                );
            }
        }

        $comparacao = $this->compararComRegiao($ultimo['valor'], $medianasDaRegiao[$ultimo['competencia']] ?? null, $quantidadeNaRegiao, $indicador, 'No dado mais recente, o valor');

        if ($comparacao['frase'] !== null) {
            $paragrafos[] = $comparacao['frase'];
        }

        return new Analise($this->tomPor($indicador, $ultimo['valor'], $comparacao['sentido'], $tendencia['sentido']), $paragrafos);
    }

    /**
     * @param  list<array{municipio_id: int, nome: string, valor: float}>  $ranking  do melhor para o pior
     */
    public function ranking(Municipio $municipio, Indicador $indicador, int $competencia, array $ranking, ?float $medianaDaRegiao): Analise
    {
        $total = count($ranking);
        $meu = null;

        foreach ($ranking as $linha) {
            if ($linha['municipio_id'] === $municipio->id) {
                $meu = $linha;
            }
        }

        if ($meu === null) {
            return $this->semDados($municipio, $indicador);
        }

        $neutro = $indicador->polaridade === Polaridade::Neutra;
        $avaliados = array_map(fn (array $linha): float => $indicador->valorAvaliado($linha['valor']), $ranking);
        $meuAvaliado = $indicador->valorAvaliado($meu['valor']);
        $melhores = count(array_filter($avaliados, fn (float $v): bool => $indicador->polaridade === Polaridade::MenorMelhor ? $v < $meuAvaliado : $v > $meuAvaliado));
        $empatados = count(array_filter($avaliados, fn (float $v): bool => $v === $meuAvaliado)) - 1;
        $posicao = $melhores + 1;
        $rotulo = $indicador->rotuloDaCompetencia($competencia);

        $empate = $empatados > 0 ? sprintf(', empatado com %d %s', $empatados, $empatados === 1 ? 'município' : 'municípios') : '';

        $paragrafos = [$neutro
            ? sprintf('Entre os %d municípios da região de saúde (%s), %s tem o %dº maior valor de “%s”: %s.', $total, $rotulo, $municipio->nome, $posicao, $indicador->nome, Formatador::valor($meu['valor'], $indicador))
            : sprintf('Entre os %d municípios da região de saúde (%s), %s ocupa a %s posição em “%s” (a 1ª é a melhor situação)%s, com %s.', $total, $rotulo, $municipio->nome, Formatador::ordinal($posicao), $indicador->nome, $empate, Formatador::valor($meu['valor'], $indicador)),
        ];

        if ($total >= 2) {
            $primeiro = $ranking[0];
            $ultimo = $ranking[$total - 1];
            $noTeto = count(array_filter($ranking, fn (array $linha): bool => $indicador->estaNoTeto($linha['valor'])));

            $paragrafos[] = match (true) {
                $neutro => sprintf('O maior valor é de %s (%s) e o menor, de %s (%s).', $primeiro['nome'], Formatador::valor($primeiro['valor'], $indicador), $ultimo['nome'], Formatador::valor($ultimo['valor'], $indicador)),
                $noTeto === $total => sprintf('Todos os %d municípios estão no patamar pleno (a partir de %s).', $total, $this->teto($indicador)),
                $noTeto > 0 => sprintf('%d dos %d municípios estão no patamar pleno (a partir de %s). A pior situação é a de %s (%s).', $noTeto, $total, $this->teto($indicador), $ultimo['nome'], Formatador::valor($ultimo['valor'], $indicador)),
                default => sprintf('A melhor situação é a de %s (%s) e a pior, a de %s (%s).', $primeiro['nome'], Formatador::valor($primeiro['valor'], $indicador), $ultimo['nome'], Formatador::valor($ultimo['valor'], $indicador)),
            };
        }

        $comparacao = $this->compararComRegiao($meu['valor'], $medianaDaRegiao, $total, $indicador, sprintf('O valor de %s', $municipio->nome));

        if ($comparacao['frase'] !== null) {
            $paragrafos[] = $comparacao['frase'];
        }

        if ($neutro || $total < self::MINIMO_NA_REGIAO) {
            return new Analise(Analise::INFORMATIVO, $paragrafos);
        }

        $terco = (int) ceil($total / 3);

        $tom = match (true) {
            $posicao <= $terco => Analise::FAVORAVEL,
            $posicao > $total - $terco => Analise::ATENCAO,
            default => Analise::INTERMEDIARIO,
        };

        return new Analise($tom, $paragrafos);
    }

    public function semDados(Municipio $municipio, Indicador $indicador): Analise
    {
        return new Analise(Analise::SEM_DADO, [
            sprintf('Ainda não há dados de “%s” para %s.', $indicador->nome, $municipio->nome),
            sprintf('Este indicador vem de %s. Se a fonte já foi atualizada, ela pode não ter publicado o dado para este município; caso contrário, a carga ainda não foi feita (administradores acompanham isso em Integrações).', $indicador->fonteRotulo()),
        ]);
    }

    /**
     * @return array{sentido: int, direcao: int, plena: bool} sentido: +1 melhora, -1 piora, 0 estável/indefinido;
     *                                                        direcao: +1 subiu, -1 caiu, 0 estável; plena: os dois valores estão no teto
     */
    public function tendencia(?float $de, float $para, Indicador $indicador): array
    {
        if ($de === null) {
            return ['sentido' => 0, 'direcao' => 0, 'plena' => false];
        }

        if ($indicador->estaNoTeto($de) && $indicador->estaNoTeto($para)) {
            return ['sentido' => 0, 'direcao' => 0, 'plena' => true];
        }

        $deAvaliado = $indicador->valorAvaliado($de);
        $diferenca = $indicador->valorAvaliado($para) - $deAvaliado;
        $limite = $indicador->unidade === '%' ? self::TOLERANCIA_EM_PONTOS : 0.01 * abs($deAvaliado);

        if (abs($diferenca) <= $limite) {
            return ['sentido' => 0, 'direcao' => 0, 'plena' => false];
        }

        $subiu = $diferenca > 0;
        $sentido = match ($indicador->polaridade) {
            Polaridade::MaiorMelhor => $subiu ? 1 : -1,
            Polaridade::MenorMelhor => $subiu ? -1 : 1,
            Polaridade::Neutra => 0,
        };

        return ['sentido' => $sentido, 'direcao' => $subiu ? 1 : -1, 'plena' => false];
    }

    /**
     * @return array{frase: string|null, sentido: int} sentido: +1 melhor que a região, -1 pior, 0 igual/indefinido
     */
    private function compararComRegiao(float $valor, ?float $mediana, ?int $quantidade, Indicador $indicador, string $sujeito = 'Esse valor'): array
    {
        if ($mediana === null || ($quantidade !== null && $quantidade < self::MINIMO_NA_REGIAO)) {
            return ['frase' => null, 'sentido' => 0];
        }

        $medianaFormatada = Formatador::valor($mediana, $indicador);

        if ($indicador->estaNoTeto($valor) && $indicador->estaNoTeto($mediana)) {
            return [
                'frase' => sprintf('%s e a mediana da região de saúde (%s) estão no patamar pleno (a partir de %s): a diferença entre eles não indica situação melhor nem pior.', $sujeito, $medianaFormatada, $this->teto($indicador)),
                'sentido' => 0,
            ];
        }

        $avaliado = $indicador->valorAvaliado($valor);
        $medianaAvaliada = $indicador->valorAvaliado($mediana);
        $diferenca = $avaliado - $medianaAvaliada;
        $igual = abs($diferenca) <= self::TOLERANCIA_RELATIVA * abs($medianaAvaliada) || ($avaliado == 0.0 && $medianaAvaliada == 0.0);

        if ($igual) {
            return ['frase' => sprintf('%s é praticamente igual à mediana da região de saúde (%s).', $sujeito, $medianaFormatada), 'sentido' => 0];
        }

        $acima = $diferenca > 0;
        $sentido = match ($indicador->polaridade) {
            Polaridade::MaiorMelhor => $acima ? 1 : -1,
            Polaridade::MenorMelhor => $acima ? -1 : 1,
            Polaridade::Neutra => 0,
        };

        $complemento = match ($sentido) {
            1 => ', o que é uma situação melhor',
            -1 => ', o que merece atenção',
            default => '',
        };

        return [
            'frase' => sprintf('%s está %s da mediana da região de saúde (%s)%s.', $sujeito, $acima ? 'acima' : 'abaixo', $medianaFormatada, $complemento),
            'sentido' => $sentido,
        ];
    }

    /**
     * @param  array{competencia: int, valor: float}  $anterior
     * @param  array{sentido: int, direcao: int, plena: bool}  $tendencia
     */
    private function frasePorTendencia(array $anterior, float $valor, Indicador $indicador, array $tendencia): string
    {
        return sprintf(
            'Em relação a %s (%s), o valor %s',
            $indicador->rotuloDaCompetencia($anterior['competencia']),
            Formatador::valor($anterior['valor'], $indicador),
            $this->verboDaTendencia($tendencia, $anterior['valor'], $valor, $indicador, false),
        );
    }

    /**
     * @param  array{sentido: int, direcao: int, plena: bool}  $tendencia
     */
    private function verboDaTendencia(array $tendencia, float $de, float $para, Indicador $indicador, bool $incluirValores = true): string
    {
        $valores = $incluirValores ? sprintf(' (de %s para %s)', Formatador::valor($de, $indicador), Formatador::valor($para, $indicador)) : '';

        if ($tendencia['plena']) {
            return sprintf('se manteve no patamar pleno%s (a partir de %s).', $valores, $this->teto($indicador));
        }

        if ($tendencia['direcao'] === 0) {
            return 'ficou estável'.$valores.'.';
        }

        $variacao = ltrim(Formatador::variacao($de, $para, $indicador), '+−');
        $juizo = match ($tendencia['sentido']) {
            1 => ', uma melhora',
            -1 => ', uma piora',
            default => '',
        };

        $frase = sprintf('%s %s%s%s', $tendencia['direcao'] > 0 ? 'subiu' : 'caiu', $variacao, $valores, $juizo);

        return str_ends_with($frase, '.') ? $frase : $frase.'.';
    }

    private function teto(Indicador $indicador): string
    {
        return Formatador::valor((float) $indicador->teto, $indicador);
    }

    private function tomPor(Indicador $indicador, float $valorAtual, int $comparacao, int $tendencia): string
    {
        if ($indicador->polaridade === Polaridade::Neutra) {
            return Analise::INFORMATIVO;
        }

        if ($indicador->polaridade === Polaridade::MaiorMelhor && $indicador->estaNoTeto($valorAtual)) {
            return Analise::FAVORAVEL;
        }

        $pontuacao = $comparacao + $tendencia;

        return match (true) {
            $comparacao === 0 && $tendencia === 0 => Analise::INTERMEDIARIO,
            $pontuacao >= 1 => Analise::FAVORAVEL,
            $pontuacao <= -1 => Analise::ATENCAO,
            default => Analise::INTERMEDIARIO,
        };
    }
}
