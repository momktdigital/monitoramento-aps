<?php

namespace App\Domain\Scoring;

/**
 * Combina as notas dos indicadores de um município em pilares e no índice final (média ponderada em dois níveis).
 *
 * Dado ausente nunca vira zero: o peso dele é redistribuído entre os indicadores presentes e a `confianca` informa
 * que parcela da metodologia foi de fato coberta. Pilar obrigatório sem nenhum dado impede o cálculo do índice.
 *
 * @phpstan-type Pilar array{nome?: string, peso: int|float, obrigatorio?: bool, componentes: array<string, array{peso: int|float}>}
 * @phpstan-type Resultado array{nota: float|null, confianca: float, calculado: bool, pilares: array<string, array{nota: float|null, completude: float}>, faltantes: list<string>}
 */
class CompositorDeIndice
{
    public function __construct(private readonly float $confiancaMinima) {}

    /**
     * @param  array<string, Pilar>  $pilares  configuração dos pilares do índice
     * @param  array<string, float|null>  $notas  indicador => nota de 0 a 100 do município (null = sem dado)
     * @param  array<string, bool>  $emUso  indicadores que a metodologia considera nesta carga de dados (os demais são ignorados)
     * @return Resultado
     */
    public function compor(array $pilares, array $notas, array $emUso): array
    {
        $notasDosPilares = [];
        $faltantes = [];
        $somaDosPesos = 0.0;
        $somaDaCompletude = 0.0;
        $somaPonderada = 0.0;
        $pesoComNota = 0.0;
        $obrigatorioAusente = false;

        foreach ($pilares as $chave => $pilar) {
            $usados = array_filter($pilar['componentes'], fn (array $componente, string $codigo): bool => ($emUso[$codigo] ?? false), ARRAY_FILTER_USE_BOTH);
            $obrigatorio = (bool) ($pilar['obrigatorio'] ?? false);

            if ($usados === [] && ! $obrigatorio) {
                continue;
            }

            $pesoTotal = 0.0;
            $pesoPresente = 0.0;
            $soma = 0.0;

            foreach ($usados as $codigo => $componente) {
                $pesoTotal += $componente['peso'];

                if (($notas[$codigo] ?? null) === null) {
                    $faltantes[] = $codigo;

                    continue;
                }

                $pesoPresente += $componente['peso'];
                $soma += $componente['peso'] * $notas[$codigo];
            }

            $nota = $pesoPresente > 0 ? $soma / $pesoPresente : null;
            $completude = $pesoTotal > 0 ? $pesoPresente / $pesoTotal : 0.0;

            $notasDosPilares[$chave] = ['nota' => $nota, 'completude' => $completude];
            $somaDosPesos += $pilar['peso'];
            $somaDaCompletude += $pilar['peso'] * $completude;

            if ($nota !== null) {
                $somaPonderada += $pilar['peso'] * $nota;
                $pesoComNota += $pilar['peso'];
            } elseif ($obrigatorio) {
                $obrigatorioAusente = true;
            }
        }

        $confianca = $somaDosPesos > 0 ? $somaDaCompletude / $somaDosPesos : 0.0;
        $nota = $pesoComNota > 0 ? $somaPonderada / $pesoComNota : null;
        $calculado = $nota !== null && ! $obrigatorioAusente && $confianca >= $this->confiancaMinima;

        return [
            'nota' => $calculado ? $nota : null,
            'confianca' => $confianca,
            'calculado' => $calculado,
            'pilares' => $notasDosPilares,
            'faltantes' => $faltantes,
        ];
    }
}
