<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Número que muda sempre que novos dados são carregados. Entra na chave de cache dos visuais:
 * dados novos invalidam o cache sem precisar limpar nada manualmente.
 */
class VersaoDosDados
{
    private const CHAVE = 'aps.versao_dos_dados';

    public static function atual(): int
    {
        return (int) Cache::get(self::CHAVE, 1);
    }

    public static function renovar(): int
    {
        $nova = (int) (microtime(true) * 1000);

        Cache::forever(self::CHAVE, $nova);

        return $nova;
    }
}
