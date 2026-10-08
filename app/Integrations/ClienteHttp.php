<?php

namespace App\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Único ponto de criação de clientes HTTP para as fontes de dados.
 * O endereço base vem sempre de `config('aps.fontes')`: fonte desconhecida é erro.
 */
class ClienteHttp
{
    public static function fonte(string $fonte): PendingRequest
    {
        $url = config("aps.fontes.{$fonte}");

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException("Fonte de dados não permitida: {$fonte}");
        }

        return Http::baseUrl($url)
            ->acceptJson()
            ->withUserAgent((string) config('aps.http.user_agent'))
            ->timeout((int) config('aps.http.timeout'))
            ->retry(
                (int) config('aps.http.tentativas'),
                (int) config('aps.http.espera_ms'),
                when: fn (Throwable $erro): bool => self::valeRepetir($erro),
                throw: false,
            );
    }

    /**
     * Repete só quando a falha é da rede ou do servidor da fonte (5xx). Erros 4xx (chave recusada,
     * parâmetro inválido) não melhoram ao repetir, e o limite de uso (429) é tratado pelo conector.
     */
    private static function valeRepetir(Throwable $erro): bool
    {
        if ($erro instanceof ConnectionException) {
            return true;
        }

        return $erro instanceof RequestException && $erro->response->serverError();
    }
}
