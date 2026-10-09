<?php

use App\Administracao\VerificadorDeSaude;
use App\Enums\OrigemExecucao;
use App\Integrations\Ingestor;
use App\Integrations\RegistroDeConectores;
use App\Models\AuditLog;
use App\Models\Integracao;
use Illuminate\Support\Facades\Cache;
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

/*
 * Batimento do agendador: a tela de administração mostra "última execução há X" e avisa quando o agendador para
 * (sem ele, as integrações não rodam sozinhas).
 */
Schedule::call(fn () => Cache::put(VerificadorDeSaude::CHAVE_DO_BATIMENTO, now()->getTimestamp(), now()->addDay()))
    ->name('aps-batimento-do-agendador')
    ->everyMinute();

// Remove da auditoria os registros mais antigos que o prazo de retenção (config aps.auditoria.retencao_dias).
Schedule::command('model:prune', ['--model' => [AuditLog::class]])->dailyAt('04:30')->name('aps-limpar-auditoria');
