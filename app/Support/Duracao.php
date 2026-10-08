<?php

namespace App\Support;

class Duracao
{
    /**
     * Duração em texto curto: "45 s", "12 min 05 s", "1 h 20 min".
     */
    public static function formatar(int $segundos): string
    {
        $segundos = max(0, $segundos);

        if ($segundos < 60) {
            return $segundos.' s';
        }

        if ($segundos < 3600) {
            return intdiv($segundos, 60).' min '.str_pad((string) ($segundos % 60), 2, '0', STR_PAD_LEFT).' s';
        }

        return intdiv($segundos, 3600).' h '.str_pad((string) intdiv($segundos % 3600, 60), 2, '0', STR_PAD_LEFT).' min';
    }
}
