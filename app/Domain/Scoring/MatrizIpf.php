<?php

namespace App\Domain\Scoring;

use App\Domain\Indicators\CalculadorDeBenchmarks;
use App\Enums\QuadranteIpf;

/**
 * Matriz INA × IDAPS: cruza necessidade com desempenho e classifica o município em um dos quatro quadrantes.
 * Também aplica a regra de efetividade ("estrutura forte, resultado fraco").
 */
class MatrizIpf
{
    /**
     * @param  array<string, string>  $quadrantes  configuração: "necessidade_<alta|baixa>_desempenho_<alto|baixo>" => chave do quadrante
     */
    public function __construct(private readonly array $quadrantes) {}

    /**
     * Ponto que separa "alto" de "baixo": a mediana dos municípios calculados no mês, ou um número fixo de 0 a 100.
     *
     * @param  list<float>  $notas
     */
    public static function corte(array $notas, string|int|float $regra): float
    {
        if (is_numeric($regra)) {
            return (float) $regra;
        }

        if ($notas === []) {
            return 50.0;
        }

        sort($notas);

        return CalculadorDeBenchmarks::percentil($notas, 0.5);
    }

    /**
     * Valor igual ao corte conta como "alto".
     */
    public function classificar(float $ina, float $idaps, float $corteIna, float $corteIdaps): QuadranteIpf
    {
        $chave = sprintf(
            'necessidade_%s_desempenho_%s',
            $ina >= $corteIna ? 'alta' : 'baixa',
            $idaps >= $corteIdaps ? 'alto' : 'baixo',
        );

        return QuadranteIpf::daChave($this->quadrantes[$chave]);
    }

    /**
     * Prioridade de 0 a 100 para ordenar os municípios: média entre a necessidade e a distância até o desempenho máximo.
     */
    public static function pontuacao(float $ina, float $idaps): float
    {
        return ($ina + (100 - $idaps)) / 2;
    }

    /**
     * @param  array{estrutura_minima: int|float, resultado_maximo: int|float}  $regra
     */
    public static function estruturaSemResultado(?float $estrutura, ?float $resultado, array $regra): bool
    {
        return $estrutura !== null
            && $resultado !== null
            && $estrutura >= $regra['estrutura_minima']
            && $resultado <= $regra['resultado_maximo'];
    }
}
