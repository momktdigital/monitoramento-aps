<?php

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Liga a confiança nos cabeçalhos X-Forwarded-* de um proxy reverso (esquema https, host e porta originais).
 * Sem isso, atrás de um proxy HTTPS o Laravel gera endereços em http e o navegador bloqueia scripts e requisições do Livewire.
 */
class ProxiesConfiaveis
{
    /**
     * @param  string|null  $valor  "*" (confia em quem estiver chamando), lista de IPs separados por vírgula, ou vazio (não confia)
     */
    public static function aplicar(?string $valor): void
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return;
        }

        TrustProxies::at($valor === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $valor)))));
    }
}
