<?php

namespace App\Domain\Narrative;

use App\Domain\Indicators\CalculadorDeBenchmarks;
use App\Enums\Polaridade;
use App\Models\Indicador;
use App\Support\Formatador;

/**
 * "Análise do cenário atual" do mapa de um indicador: amplitude entre os municípios, mediana do estado e onde o
 * município escolhido está. Só emite juízo (melhor/pior) quando o indicador tem sentido bom ou ruim definido.
 */
class AnalistaDoMapa
{
    /** Diferença relativa até a qual um valor é considerado "próximo" da mediana. */
    private const TOLERANCIA_RELATIVA = 0.03;

    /**
     * @param  array<int, float>  $valores  município => valor na competência exibida
     * @param  array<int, string>  $nomes  município => nome
     */
    public function analisar(Indicador $indicador, string $competenciaRotulo, array $valores, array $nomes, ?int $municipioId, int $totalDeMunicipios): Analise
    {
        if (count($valores) < 2) {
            return new Analise(Analise::SEM_DADO, ["Há dados de “{$indicador->nome}” para poucos municípios nesta competência, então não é possível comparar."]);
        }

        $ordenados = $valores;
        asort($ordenados);
        $menorId = array_key_first($ordenados);
        $maiorId = array_key_last($ordenados);
        $mediana = CalculadorDeBenchmarks::percentil(array_values($ordenados), 0.5);

        $paragrafos = [
            sprintf(
                'Em %s, “%s” vai de %s (%s) a %s (%s) entre os %d municípios com dado. A mediana do estado é %s.',
                $competenciaRotulo,
                $indicador->nome,
                Formatador::valor($valores[$menorId], $indicador),
                $nomes[$menorId] ?? 'município',
                Formatador::valor($valores[$maiorId], $indicador),
                $nomes[$maiorId] ?? 'município',
                count($valores),
                Formatador::valor($mediana, $indicador),
            ),
        ];

        $semDado = $totalDeMunicipios - count($valores);

        if ($semDado > 0) {
            $paragrafos[] = sprintf('%d %s sem dado nesta competência e aparece%s em cinza no mapa.', $semDado, $semDado === 1 ? 'município está' : 'municípios estão', $semDado === 1 ? '' : 'm');
        }

        $tom = Analise::INFORMATIVO;

        if ($municipioId !== null && isset($valores[$municipioId])) {
            [$frase, $tom] = $this->doMunicipio($indicador, $valores, $mediana, $municipioId, $nomes[$municipioId] ?? 'O município');
            $paragrafos[] = $frase;
        }

        return new Analise($tom, $paragrafos);
    }

    /**
     * @param  array<int, float>  $valores
     * @return array{0: string, 1: string} frase e tom
     */
    private function doMunicipio(Indicador $indicador, array $valores, float $mediana, int $municipioId, string $nome): array
    {
        $valor = $valores[$municipioId];
        $relativa = $mediana == 0.0 ? abs($valor - $mediana) : abs($valor - $mediana) / abs($mediana);
        $posicaoRelativa = $relativa <= self::TOLERANCIA_RELATIVA ? 'próximo da' : ($valor > $mediana ? 'acima da' : 'abaixo da');

        $frase = sprintf('%s tem %s, %s mediana do estado.', $nome, Formatador::valor($valor, $indicador), $posicaoRelativa);

        if ($indicador->polaridade === Polaridade::Neutra) {
            return [$frase, Analise::INFORMATIVO];
        }

        $melhores = 0;

        foreach ($valores as $outro) {
            $avaliadoOutro = $indicador->valorAvaliado($outro);
            $avaliadoNome = $indicador->valorAvaliado($valor);

            if ($indicador->polaridade === Polaridade::MaiorMelhor ? $avaliadoOutro > $avaliadoNome : $avaliadoOutro < $avaliadoNome) {
                $melhores++;
            }
        }

        $posicao = $melhores + 1;
        $total = count($valores);
        $frase .= sprintf(' É a %s posição entre %d municípios (a 1ª é a melhor situação).', Formatador::ordinal($posicao), $total);

        $tom = match (true) {
            $posicao <= $total / 3 => Analise::FAVORAVEL,
            $posicao > $total * 2 / 3 => Analise::ATENCAO,
            default => Analise::INTERMEDIARIO,
        };

        return [$frase, $tom];
    }
}
