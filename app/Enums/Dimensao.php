<?php

namespace App\Enums;

enum Dimensao: string
{
    case Necessidade = 'ina';
    case Desempenho = 'idaps';
    case Contexto = 'contexto';
    case Indices = 'indices';

    public function rotulo(): string
    {
        return match ($this) {
            self::Necessidade => 'Necessidade da APS',
            self::Desempenho => 'Desempenho da APS',
            self::Contexto => 'Contexto',
            self::Indices => 'Índices',
        };
    }
}
