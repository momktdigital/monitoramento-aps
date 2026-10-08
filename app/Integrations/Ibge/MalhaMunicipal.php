<?php

namespace App\Integrations\Ibge;

use App\Integrations\ClienteHttp;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Contorno dos municípios de uma UF (malha do IBGE, qualidade mínima: poucas dezenas de KB), usado nos mapas.
 *
 * A malha muda raramente. Fica em disco e só é baixada de novo depois de `DIAS_DE_VALIDADE`; se o IBGE estiver fora do ar
 * na hora de atualizar, o arquivo antigo continua servindo. Nada vindo do usuário entra na URL: o código da UF é validado.
 */
class MalhaMunicipal
{
    private const DIAS_DE_VALIDADE = 90;

    /**
     * GeoJSON (texto) com um polígono por município; o código IBGE de 7 dígitos está em `properties.codarea`.
     */
    public function daUf(int $codigoUf): ?string
    {
        if ($codigoUf < 11 || $codigoUf > 53) {
            return null;
        }

        $caminho = "malhas/uf-{$codigoUf}.geojson";
        $disco = Storage::disk('local');
        $atual = $disco->exists($caminho) ? $disco->get($caminho) : null;

        if ($atual !== null && $disco->lastModified($caminho) > now()->subDays(self::DIAS_DE_VALIDADE)->getTimestamp()) {
            return $atual;
        }

        $novo = $this->baixar($codigoUf);

        if ($novo === null) {
            return $atual;
        }

        $disco->put($caminho, $novo);

        return $novo;
    }

    private function baixar(int $codigoUf): ?string
    {
        try {
            $resposta = ClienteHttp::fonte('ibge_malhas')->get("estados/{$codigoUf}", [
                'intrarregiao' => 'municipio',
                'formato' => 'application/vnd.geo+json',
                'qualidade' => 'minima',
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $resposta->successful()) {
            return null;
        }

        $dados = $resposta->json();

        return $this->valida($dados) ? json_encode($dados, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * Só aceita o que o mapa sabe desenhar: coleção de polígonos, todos com código de município.
     */
    private function valida(mixed $dados): bool
    {
        if (! is_array($dados) || ($dados['type'] ?? null) !== 'FeatureCollection' || ! is_array($dados['features'] ?? null) || $dados['features'] === []) {
            return false;
        }

        foreach ($dados['features'] as $feature) {
            if (! is_array($feature)
                || ! in_array($feature['geometry']['type'] ?? null, ['Polygon', 'MultiPolygon'], true)
                || preg_match('/^\d{7}$/', (string) ($feature['properties']['codarea'] ?? '')) !== 1) {
                return false;
            }
        }

        return true;
    }
}
