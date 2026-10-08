<?php

namespace App\Models;

use Database\Factories\ValorIndicadorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha da tabela mais consultada do sistema (chave composta município + indicador + competência).
 * Não há chave simples: grave sempre em lote com `upsert()` e leia por consultas, nunca com `save()`.
 */
#[Fillable(['municipio_id', 'indicador_id', 'competencia', 'valor', 'numerador', 'denominador'])]
class ValorIndicador extends Model
{
    /** @use HasFactory<ValorIndicadorFactory> */
    use HasFactory;

    public const CHAVE_UNICA = ['municipio_id', 'indicador_id', 'competencia'];

    public const COLUNAS_ATUALIZAVEIS = ['valor', 'numerador', 'denominador'];

    protected $table = 'valores_indicador';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'municipio_id' => 'integer',
            'indicador_id' => 'integer',
            'competencia' => 'integer',
            'valor' => 'float',
            'numerador' => 'float',
            'denominador' => 'float',
        ];
    }

    /**
     * Grava ou atualiza várias linhas de uma vez (idempotente: reexecutar não duplica).
     *
     * @param  list<array{municipio_id: int, indicador_id: int, competencia: int, valor: float|int|null, numerador?: float|int|null, denominador?: float|int|null}>  $linhas
     */
    public static function gravarEmLote(array $linhas): int
    {
        if ($linhas === []) {
            return 0;
        }

        $linhas = array_map(fn (array $linha): array => $linha + ['numerador' => null, 'denominador' => null], $linhas);

        return static::upsert($linhas, self::CHAVE_UNICA, self::COLUNAS_ATUALIZAVEIS);
    }

    /**
     * @return BelongsTo<Municipio, $this>
     */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class, 'municipio_id');
    }

    /**
     * @return BelongsTo<Indicador, $this>
     */
    public function indicador(): BelongsTo
    {
        return $this->belongsTo(Indicador::class, 'indicador_id');
    }
}
