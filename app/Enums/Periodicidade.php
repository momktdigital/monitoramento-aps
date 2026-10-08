<?php

namespace App\Enums;

enum Periodicidade: string
{
    case Mensal = 'mensal';
    case Quadrimestral = 'quadrimestral';
    case Anual = 'anual';

    public function rotulo(): string
    {
        return match ($this) {
            self::Mensal => 'Mensal',
            self::Quadrimestral => 'Quadrimestral',
            self::Anual => 'Anual',
        };
    }
}
