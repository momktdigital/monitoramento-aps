<?php

namespace App\Administracao;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Leitura e manutenção da fila de trabalhos (integrações em segundo plano). Só a fila em banco de dados mostra números.
 */
class Fila
{
    public function usaBanco(): bool
    {
        return config('queue.default') === 'database' && Schema::hasTable(config('queue.connections.database.table', 'jobs'));
    }

    public function pendentes(): int
    {
        return $this->usaBanco() ? DB::table(config('queue.connections.database.table', 'jobs'))->count() : 0;
    }

    /**
     * Há quantos segundos o trabalho pendente mais antigo está esperando (nulo se não há nenhum). Muito tempo
     * esperando indica que o processador de filas está desligado.
     */
    public function esperaMaisLongaEmSegundos(): ?int
    {
        if (! $this->usaBanco()) {
            return null;
        }

        $disponivel = DB::table(config('queue.connections.database.table', 'jobs'))->whereNull('reserved_at')->min('available_at');

        return $disponivel === null ? null : max(0, now()->getTimestamp() - (int) $disponivel);
    }

    /**
     * Trabalhos que falharam, do mais recente para o mais antigo.
     *
     * @return list<array{id: string, trabalho: string, fila: string, falhou_em: Carbon, motivo: string}>
     */
    public function falhas(int $limite = 50): array
    {
        $registros = array_slice(array_reverse(app('queue.failer')->all()), 0, $limite);

        return array_map(fn (object $falha): array => [
            'id' => (string) $falha->id,
            'trabalho' => (string) (json_decode($falha->payload, true)['displayName'] ?? 'Trabalho desconhecido'),
            'fila' => (string) $falha->queue,
            'falhou_em' => Carbon::parse($falha->failed_at),
            'motivo' => Str::limit(trim(Str::before((string) $falha->exception, "\n")), 240),
        ], $registros);
    }

    public function totalDeFalhas(): int
    {
        return count(app('queue.failer')->all());
    }

    public function reenviar(string $id): bool
    {
        if (app('queue.failer')->find($id) === null) {
            return false;
        }

        Artisan::call('queue:retry', ['id' => [$id]]);

        return true;
    }

    public function descartar(string $id): bool
    {
        return app('queue.failer')->forget($id);
    }

    public function descartarTodas(): int
    {
        $total = $this->totalDeFalhas();

        app('queue.failer')->flush();

        return $total;
    }
}
