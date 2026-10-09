<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Atualiza a lista de municípios e as regiões de saúde a partir do IBGE e da API Dados Abertos, em segundo plano.
 * Os marcadores de município ativo e de piloto não são alterados.
 */
class SincronizarMunicipiosJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function handle(): void
    {
        Artisan::call('aps:sincronizar-municipios');
    }
}
