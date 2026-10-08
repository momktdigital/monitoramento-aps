<?php

namespace App\Enums;

enum OrigemExecucao: string
{
    case Agendada = 'agendada';
    case Manual = 'manual';
    case Comando = 'comando';

    public function rotulo(): string
    {
        return match ($this) {
            self::Agendada => 'Agendada',
            self::Manual => 'Manual (tela)',
            self::Comando => 'Linha de comando',
        };
    }
}
