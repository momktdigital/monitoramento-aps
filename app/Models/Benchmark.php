<?php

namespace App\Models;

use App\Enums\EscopoBenchmark;
use Database\Factories\BenchmarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estatística pré-calculada (média, mediana, quartis) de um indicador numa competência e escopo.
 */
#[Fillable([
    'indicador_id', 'competencia', 'escopo', 'escopo_id',
    'quantidade', 'media', 'mediana', 'p25', 'p75', 'minimo', 'maximo',
])]
class Benchmark extends Model
{
    /** @use HasFactory<BenchmarkFactory> */
    use HasFactory;

    public const CHAVE_UNICA = ['indicador_id', 'competencia', 'escopo', 'escopo_id'];

    protected $table = 'benchmarks';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'escopo' => EscopoBenchmark::class,
            'competencia' => 'integer',
            'escopo_id' => 'integer',
            'quantidade' => 'integer',
            'media' => 'float',
            'mediana' => 'float',
            'p25' => 'float',
            'p75' => 'float',
            'minimo' => 'float',
            'maximo' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Indicador, $this>
     */
    public function indicador(): BelongsTo
    {
        return $this->belongsTo(Indicador::class, 'indicador_id');
    }
}
