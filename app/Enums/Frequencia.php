<?php

namespace App\Enums;

enum Frequencia: string
{
    case Manual = 'manual';
    case Diaria = 'diaria';
    case Semanal = 'semanal';
    case Mensal = 'mensal';

    public function rotulo(): string
    {
        return match ($this) {
            self::Manual => 'Somente manual',
            self::Diaria => 'Todos os dias',
            self::Semanal => 'Toda segunda-feira',
            self::Mensal => 'Todo dia 2 do mês',
        };
    }
}
