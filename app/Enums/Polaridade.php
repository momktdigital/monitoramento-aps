<?php

namespace App\Enums;

/**
 * Sentido desejável do indicador: define a cor/avaliação (semáforo) e como o valor entra nos índices.
 */
enum Polaridade: string
{
    case MaiorMelhor = 'maior_melhor';
    case MenorMelhor = 'menor_melhor';
    case Neutra = 'neutra';

    public function rotulo(): string
    {
        return match ($this) {
            self::MaiorMelhor => 'Quanto maior, melhor',
            self::MenorMelhor => 'Quanto menor, melhor',
            self::Neutra => 'Informativo (sem sentido bom ou ruim)',
        };
    }
}
