<?php

namespace App\Integrations\Datasus;

use InvalidArgumentException;

/**
 * Caminhos dos arquivos no FTP público do DATASUS (/dissemin/publicos). Todos os trechos variáveis
 * são validados: sigla de UF com duas letras, ano com quatro dígitos e mês de 1 a 12.
 */
class NomesDeArquivo
{
    /** Internações (SIH/SUS, AIH reduzida), um arquivo por UF do hospital e mês de processamento. */
    public static function sih(string $uf, int $ano, int $mes): string
    {
        self::validar($uf, $ano, $mes);

        return sprintf('/dissemin/publicos/SIHSUS/200801_/Dados/RD%s%02d%02d.dbc', $uf, $ano % 100, $mes);
    }

    /** Nascidos vivos (SINASC), um arquivo por UF de residência da mãe e ano. */
    public static function sinasc(string $uf, int $ano): string
    {
        self::validar($uf, $ano, 1);

        return sprintf('/dissemin/publicos/SINASC/1996_/Dados/DNRES/DN%s%04d.dbc', $uf, $ano);
    }

    /** Óbitos (SIM, CID-10), um arquivo por UF de residência e ano. */
    public static function sim(string $uf, int $ano): string
    {
        self::validar($uf, $ano, 1);

        return sprintf('/dissemin/publicos/SIM/CID10/DORES/DO%s%04d.dbc', $uf, $ano);
    }

    private static function validar(string $uf, int $ano, int $mes): void
    {
        if (preg_match('/^[A-Z]{2}$/', $uf) !== 1 || $ano < 1996 || $ano > 2100 || $mes < 1 || $mes > 12) {
            throw new InvalidArgumentException('UF, ano ou mês inválidos para um arquivo do DATASUS.');
        }
    }
}
