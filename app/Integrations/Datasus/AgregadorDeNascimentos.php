<?php

namespace App\Integrations\Datasus;

/**
 * Agrega, por município de residência da mãe, os nascidos vivos do SINASC.
 *
 * Pré-natal: CONSULTAS = 1 (nenhuma), 2 (1 a 3), 3 (4 a 6), 4 (7 ou mais) e 9 (ignorado). "Informado" são
 * os códigos 1 a 4; o percentual de 7+ consultas usa só eles como denominador.
 * Peso: em gramas; vazio, zero ou 9999 significam não informado. Baixo peso é menos de 2.500 g.
 */
class AgregadorDeNascimentos
{
    public const CAMPOS = ['CODMUNRES', 'CONSULTAS', 'PESO'];

    /**
     * @param  iterable<array<string, string>>  $registros
     * @param  array<string, int>  $municipiosPorCodigoDeSeisDigitos
     * @return array<int, array{nascidos: int, consultas_informadas: int, consultas_7_ou_mais: int, peso_informado: int, baixo_peso: int}>
     */
    public function agregar(iterable $registros, array $municipiosPorCodigoDeSeisDigitos): array
    {
        $resultado = [];

        foreach ($registros as $registro) {
            $municipio = $municipiosPorCodigoDeSeisDigitos[substr($registro['CODMUNRES'] ?? '', 0, 6)] ?? null;

            if ($municipio === null) {
                continue;
            }

            $resultado[$municipio] ??= ['nascidos' => 0, 'consultas_informadas' => 0, 'consultas_7_ou_mais' => 0, 'peso_informado' => 0, 'baixo_peso' => 0];
            $resultado[$municipio]['nascidos']++;

            $consultas = $registro['CONSULTAS'] ?? '';

            if (in_array($consultas, ['1', '2', '3', '4'], true)) {
                $resultado[$municipio]['consultas_informadas']++;

                if ($consultas === '4') {
                    $resultado[$municipio]['consultas_7_ou_mais']++;
                }
            }

            $peso = $registro['PESO'] ?? '';

            if (ctype_digit($peso) && (int) $peso > 0 && (int) $peso !== 9999) {
                $resultado[$municipio]['peso_informado']++;

                if ((int) $peso < 2500) {
                    $resultado[$municipio]['baixo_peso']++;
                }
            }
        }

        return $resultado;
    }
}
