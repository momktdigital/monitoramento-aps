<?php

namespace App\Integrations\Ibge;

use App\Integrations\ClienteHttp;
use Illuminate\Http\Client\RequestException;

class Localidades
{
    /**
     * Municípios de uma UF segundo a API de Localidades do IBGE.
     *
     * @return list<array{id: int, nome: string, regiao_imediata_codigo: int|null, regiao_imediata_nome: string|null}>
     *
     * @throws RequestException
     */
    public function municipiosDaUf(string $uf): array
    {
        $resposta = ClienteHttp::fonte('ibge_localidades')
            ->get('estados/'.strtoupper($uf).'/municipios')
            ->throw();

        return collect($resposta->json())
            ->map(fn (array $municipio): array => [
                'id' => (int) $municipio['id'],
                'nome' => (string) $municipio['nome'],
                'regiao_imediata_codigo' => isset($municipio['regiao-imediata']['id']) ? (int) $municipio['regiao-imediata']['id'] : null,
                'regiao_imediata_nome' => $municipio['regiao-imediata']['nome'] ?? null,
            ])
            ->sortBy('id')
            ->values()
            ->all();
    }
}
