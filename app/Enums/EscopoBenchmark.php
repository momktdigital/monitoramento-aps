<?php

namespace App\Enums;

/**
 * Nível geográfico de comparação. O valor numérico é gravado na tabela `benchmarks`.
 */
enum EscopoBenchmark: int
{
    case RegiaoSaude = 1;
    case Uf = 2;
    case Brasil = 3;

    public function rotulo(): string
    {
        return match ($this) {
            self::RegiaoSaude => 'Região de saúde',
            self::Uf => 'Estado',
            self::Brasil => 'Brasil',
        };
    }
}
