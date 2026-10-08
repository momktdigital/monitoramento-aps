<?php

namespace App\Integrations\DadosAbertos;

use App\Integrations\ClienteHttp;
use Illuminate\Http\Client\RequestException;

class RegioesDeSaude
{
    private const POR_PAGINA = 200;

    /**
     * Região e macrorregião de saúde de cada município de uma UF, indexadas pelo código IBGE de 6 dígitos.
     *
     * @return array<int, array{regiao_saude_codigo: int, regiao_saude_nome: string, macrorregiao_saude_codigo: int, macrorregiao_saude_nome: string}>
     *
     * @throws RequestException
     */
    public function porUf(string $uf): array
    {
        $resultado = [];
        $deslocamento = 0;

        do {
            $itens = ClienteHttp::fonte('dados_abertos')
                ->get('macrorregiao-e-regiao-de-saude/municipio', [
                    'sigla_uf' => strtoupper($uf),
                    'limit' => self::POR_PAGINA,
                    'offset' => $deslocamento,
                ])
                ->throw()
                ->json('macrorregiao_regiao_saude_municipios', []);

            foreach ($itens as $item) {
                $resultado[(int) $item['codigo_municipio']] = [
                    'regiao_saude_codigo' => (int) $item['codigo_regiao_saude'],
                    'regiao_saude_nome' => (string) $item['regiao_saude'],
                    'macrorregiao_saude_codigo' => (int) $item['codigo_macrorregiao_saude'],
                    'macrorregiao_saude_nome' => (string) $item['macrorregiao_saude'],
                ];
            }

            $deslocamento += self::POR_PAGINA;
        } while (count($itens) === self::POR_PAGINA);

        return $resultado;
    }
}
