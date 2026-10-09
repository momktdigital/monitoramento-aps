<?php

namespace App\Jobs;

use App\Domain\Scoring\CalculadorDeIndices;
use App\Models\Metodologia;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Recalcula INA, IDAPS e IPF com a metodologia ativa, em segundo plano. O andamento fica no cache para a tela de
 * administração mostrar "na fila", "calculando", "concluído" ou o erro.
 */
class RecalcularIndices implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const CHAVE_DO_ANDAMENTO = 'aps.recalculo_de_indices';

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    /**
     * @return array{estado: string, em: int, detalhe: string|null, linhas: int|null}|null
     */
    public static function andamento(): ?array
    {
        return Cache::get(self::CHAVE_DO_ANDAMENTO);
    }

    public static function registrarNaFila(): void
    {
        self::gravar('na_fila');
    }

    public function handle(CalculadorDeIndices $calculador): void
    {
        self::gravar('calculando');

        $resultado = $calculador->calcular(Metodologia::sincronizar());

        self::gravar('concluido', 'Metodologia versão '.$resultado['metodologia'].'.', $resultado['linhas']);
    }

    public function failed(?Throwable $erro): void
    {
        self::gravar('erro', $erro?->getMessage());
    }

    private static function gravar(string $estado, ?string $detalhe = null, ?int $linhas = null): void
    {
        Cache::put(self::CHAVE_DO_ANDAMENTO, ['estado' => $estado, 'em' => now()->getTimestamp(), 'detalhe' => $detalhe, 'linhas' => $linhas], now()->addDays(7));
    }
}
