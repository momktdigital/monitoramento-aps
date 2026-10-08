<?php

namespace App\Integrations\Datasus;

/**
 * Conta, por município de residência, os óbitos de menores de 1 ano do SIM.
 *
 * O campo IDADE tem 3 posições: a primeira é a unidade (0 minutos, 1 horas, 2 dias, 3 meses, 4 anos,
 * 5 centenas de anos) e as outras duas, a quantidade. Menor de 1 ano = unidade 0 a 3. "000" é idade
 * ignorada. Óbitos fetais (TIPOBITO = 1) não entram: nascidos mortos não compõem a mortalidade infantil.
 */
class AgregadorDeObitos
{
    public const CAMPOS = ['TIPOBITO', 'CODMUNRES', 'IDADE'];

    /**
     * @param  iterable<array<string, string>>  $registros
     * @param  array<string, int>  $municipiosPorCodigoDeSeisDigitos
     * @return array<int, int> município => óbitos de menores de 1 ano
     */
    public function agregar(iterable $registros, array $municipiosPorCodigoDeSeisDigitos): array
    {
        $resultado = [];

        foreach ($registros as $registro) {
            $idade = $registro['IDADE'] ?? '';

            if (($registro['TIPOBITO'] ?? '') === '1' || strlen($idade) !== 3 || $idade === '000' || ! in_array($idade[0], ['0', '1', '2', '3'], true)) {
                continue;
            }

            $municipio = $municipiosPorCodigoDeSeisDigitos[substr($registro['CODMUNRES'] ?? '', 0, 6)] ?? null;

            if ($municipio !== null) {
                $resultado[$municipio] = ($resultado[$municipio] ?? 0) + 1;
            }
        }

        return $resultado;
    }
}
