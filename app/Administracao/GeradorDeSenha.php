<?php

namespace App\Administracao;

/**
 * Senhas temporárias que sempre atendem à política do sistema (mínimo de 12 caracteres, maiúsculas, minúsculas,
 * números e símbolos), sem caracteres que se confundem na leitura (0/O, 1/l/I).
 */
class GeradorDeSenha
{
    private const MAIUSCULAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const MINUSCULAS = 'abcdefghijkmnpqrstuvwxyz';

    private const NUMEROS = '23456789';

    private const SIMBOLOS = '!@#$%&*?-_+=';

    public static function temporaria(int $tamanho = 16): string
    {
        $tamanho = max(12, $tamanho);
        $todos = self::MAIUSCULAS.self::MINUSCULAS.self::NUMEROS.self::SIMBOLOS;

        // Garante pelo menos um de cada tipo e completa com o resto, embaralhando no fim.
        $caracteres = [self::sorteio(self::MAIUSCULAS), self::sorteio(self::MINUSCULAS), self::sorteio(self::NUMEROS), self::sorteio(self::SIMBOLOS)];

        while (count($caracteres) < $tamanho) {
            $caracteres[] = self::sorteio($todos);
        }

        for ($i = count($caracteres) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$caracteres[$i], $caracteres[$j]] = [$caracteres[$j], $caracteres[$i]];
        }

        return implode('', $caracteres);
    }

    private static function sorteio(string $conjunto): string
    {
        return $conjunto[random_int(0, strlen($conjunto) - 1)];
    }
}
