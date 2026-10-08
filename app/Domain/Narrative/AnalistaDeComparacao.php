<?php

namespace App\Domain\Narrative;

use App\Enums\Polaridade;
use App\Enums\QuadranteIpf;
use App\Models\Indicador;
use App\Models\IndiceMunicipio;
use App\Support\Competencia;
use App\Support\Formatador;

/**
 * "Análise do cenário atual" da comparação entre municípios: quem lidera em cada índice, em que quadrante cada um está
 * e onde está a maior diferença entre eles. Regras determinísticas, sem IA externa.
 */
class AnalistaDeComparacao
{
    /** Diferença (pontos de 0 a 100) até a qual dois municípios são considerados praticamente empatados num índice. */
    private const EMPATE_EM_PONTOS = 3.0;

    /** Diferença relativa mínima para um indicador ser citado como a "maior diferença". */
    private const DIFERENCA_RELEVANTE = 0.1;

    /**
     * @param  array<int, string>  $nomes  município => nome
     * @param  array<int, IndiceMunicipio>  $indices  município => índices do mês de referência
     * @param  array<int, array<int, array{competencia: int, valor: float}>>  $valores  município => indicador => último valor
     * @param  list<Indicador>  $indicadores
     */
    public function analisar(array $nomes, array $indices, array $valores, array $indicadores, ?int $competencia): Analise
    {
        if (count($nomes) < 2) {
            return new Analise(Analise::SEM_DADO, ['Escolha pelo menos dois municípios para ver a comparação.']);
        }

        $paragrafos = [];

        foreach (['idaps' => 'Quanto ao desempenho da Atenção Primária (IDAPS)', 'ina' => 'Quanto à necessidade da população (INA)'] as $coluna => $descricao) {
            if ($frase = $this->fraseDoIndice($coluna, $descricao, $nomes, $indices, $competencia)) {
                $paragrafos[] = $frase;
            }
        }

        if ($frase = $this->fraseDosQuadrantes($nomes, $indices)) {
            $paragrafos[] = $frase;
        }

        if ($frase = $this->fraseDaMaiorDiferenca($nomes, $valores, $indicadores)) {
            $paragrafos[] = $frase;
        }

        if ($paragrafos === []) {
            return new Analise(Analise::SEM_DADO, ['Não há índices nem indicadores com dados para comparar estes municípios.']);
        }

        return new Analise(Analise::INFORMATIVO, $paragrafos);
    }

    /**
     * @param  array<int, string>  $nomes
     * @param  array<int, IndiceMunicipio>  $indices
     */
    private function fraseDoIndice(string $coluna, string $descricao, array $nomes, array $indices, ?int $competencia): ?string
    {
        $notas = [];

        foreach ($nomes as $id => $nome) {
            $nota = ($indices[$id] ?? null)?->{$coluna};

            if ($nota !== null) {
                $notas[$id] = $nota;
            }
        }

        if (count($notas) < 2) {
            return null;
        }

        arsort($notas);
        $maior = array_key_first($notas);
        $menor = array_key_last($notas);
        $diferenca = $notas[$maior] - $notas[$menor];
        $mes = $competencia === null ? '' : ' em '.Competencia::rotulo($competencia);

        if ($diferenca < self::EMPATE_EM_PONTOS) {
            return sprintf('%s%s, os municípios escolhidos estão praticamente empatados (diferença de %s pontos, de 0 a 100).', $descricao, $mes, Formatador::numero($diferenca, 0));
        }

        return sprintf(
            '%s%s, %s tem a maior nota (%s) e %s a menor (%s): uma diferença de %s pontos, em uma escala de 0 a 100.',
            $descricao,
            $mes,
            $nomes[$maior],
            Formatador::numero($notas[$maior], 0),
            $nomes[$menor],
            Formatador::numero($notas[$menor], 0),
            Formatador::numero($diferenca, 0),
        );
    }

    /**
     * @param  array<int, string>  $nomes
     * @param  array<int, IndiceMunicipio>  $indices
     */
    private function fraseDosQuadrantes(array $nomes, array $indices): ?string
    {
        $partes = [];

        foreach ($nomes as $id => $nome) {
            $quadrante = ($indices[$id] ?? null)?->ipf_quadrante;

            if ($quadrante instanceof QuadranteIpf) {
                $partes[] = sprintf('%s em “%s”', $nome, $quadrante->rotulo());
            }
        }

        return count($partes) >= 2 ? 'Na matriz INA × IDAPS: '.implode('; ', $partes).'.' : null;
    }

    /**
     * @param  array<int, string>  $nomes
     * @param  array<int, array<int, array{competencia: int, valor: float}>>  $valores
     * @param  list<Indicador>  $indicadores
     */
    private function fraseDaMaiorDiferenca(array $nomes, array $valores, array $indicadores): ?string
    {
        $melhor = null;

        foreach ($indicadores as $indicador) {
            if ($indicador->polaridade === Polaridade::Neutra) {
                continue;
            }

            $doIndicador = [];

            foreach ($nomes as $id => $nome) {
                if (isset($valores[$id][$indicador->id])) {
                    $doIndicador[$id] = $valores[$id][$indicador->id]['valor'];
                }
            }

            if (count($doIndicador) < 2) {
                continue;
            }

            $avaliados = array_map(fn (float $v): float => $indicador->valorAvaliado($v), $doIndicador);
            $alto = max($avaliados);
            $baixo = min($avaliados);

            if ($alto == 0.0 && $baixo == 0.0) {
                continue;
            }

            $relativa = ($alto - $baixo) / max(abs($alto), abs($baixo));

            if ($relativa >= self::DIFERENCA_RELEVANTE && ($melhor === null || $relativa > $melhor['relativa'])) {
                $porMelhor = $indicador->polaridade === Polaridade::MaiorMelhor;
                $idBom = array_search($porMelhor ? $alto : $baixo, $avaliados, true);
                $idRuim = array_search($porMelhor ? $baixo : $alto, $avaliados, true);

                $melhor = ['relativa' => $relativa, 'indicador' => $indicador, 'bom' => $idBom, 'ruim' => $idRuim, 'valores' => $doIndicador];
            }
        }

        if ($melhor === null) {
            return null;
        }

        return sprintf(
            'Entre os indicadores com sentido bom ou ruim, a maior diferença está em “%s”: %s tem %s, enquanto %s tem %s.',
            $melhor['indicador']->nome,
            $nomes[$melhor['bom']],
            Formatador::valor($melhor['valores'][$melhor['bom']], $melhor['indicador']),
            $nomes[$melhor['ruim']],
            Formatador::valor($melhor['valores'][$melhor['ruim']], $melhor['indicador']),
        );
    }
}
