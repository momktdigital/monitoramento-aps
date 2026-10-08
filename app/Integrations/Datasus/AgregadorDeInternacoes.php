<?php

namespace App\Integrations\Datasus;

/**
 * Conta as internações do SIH/SUS por município de residência e mês.
 *
 * Regras:
 *  - só AIH tipo 1 (IDENT = 1): as AIH de continuação (tipo 5) repetiriam o mesmo paciente;
 *  - município de residência (MUNIC_RES) precisa estar entre os carregados;
 *  - "total" não inclui as internações obstétricas (diagnóstico principal no capítulo XV da CID-10, "O"),
 *    exceto as que fazem parte da lista de ICSAP (O23, infecção urinária na gestação), para que o
 *    numerador esteja sempre contido no denominador.
 */
class AgregadorDeInternacoes
{
    public const CAMPOS = ['ANO_CMPT', 'MES_CMPT', 'IDENT', 'MUNIC_RES', 'DIAG_PRINC'];

    public function __construct(private readonly ListaIcsap $lista) {}

    /**
     * @param  iterable<array<string, string>>  $registros
     * @param  array<string, int>  $municipiosPorCodigoDeSeisDigitos
     * @return array<int, array<int, array{icsap: int, total: int}>> município => competência (AAAAMM) => contagens
     */
    public function agregar(iterable $registros, array $municipiosPorCodigoDeSeisDigitos): array
    {
        $resultado = [];

        foreach ($registros as $registro) {
            if (($registro['IDENT'] ?? '') !== '1') {
                continue;
            }

            $municipio = $municipiosPorCodigoDeSeisDigitos[substr($registro['MUNIC_RES'] ?? '', 0, 6)] ?? null;
            $ano = $registro['ANO_CMPT'] ?? '';
            $mes = $registro['MES_CMPT'] ?? '';

            if ($municipio === null || ! ctype_digit($ano) || ! ctype_digit($mes) || strlen($ano) !== 4) {
                continue;
            }

            $diagnostico = strtoupper(trim($registro['DIAG_PRINC'] ?? ''));
            $sensivel = $this->lista->contem($diagnostico);

            if (! $sensivel && str_starts_with($diagnostico, 'O')) {
                continue;
            }

            $competencia = (int) $ano * 100 + (int) $mes;
            $resultado[$municipio][$competencia] ??= ['icsap' => 0, 'total' => 0];
            $resultado[$municipio][$competencia]['total']++;

            if ($sensivel) {
                $resultado[$municipio][$competencia]['icsap']++;
            }
        }

        return $resultado;
    }
}
