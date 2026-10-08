<?php

use App\Enums\OrigemExecucao;
use App\Integrations\Ingestor;
use App\Integrations\RegistroDeConectores;
use App\Models\Integracao;
use Illuminate\Support\Facades\Schedule;

/*
 * A cada minuto verifica quais integrações venceram segundo a frequência configurada na tela de
 * Integrações e as coloca na fila. Basta manter `php artisan schedule:work` (ou o Agendador de
 * Tarefas do Windows chamando `schedule:run` a cada minuto) e um processador de filas em execução.
 */
Schedule::call(function (): void {
    $ingestor = app(Ingestor::class);

    RegistroDeConectores::garantirIntegracoes()
        ->filter(fn (Integracao $integracao): bool => $integracao->estaVencida(now()))
        ->each(fn (Integracao $integracao) => $ingestor->enfileirar($integracao, OrigemExecucao::Agendada));
})->name('aps-integracoes-agendadas')->everyMinute()->withoutOverlapping();
