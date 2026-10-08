<?php

namespace App\Models;

use App\Enums\QuadranteIpf;
use Database\Factories\IndiceMunicipioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Índices de um município em um mês de referência (chave composta, sem timestamps). Grave em lote com `insert()`.
 */
#[Fillable([
    'municipio_id', 'competencia', 'metodologia_id', 'ina', 'idaps', 'idaps_estrutura', 'idaps_resultado',
    'ipf_quadrante', 'ipf_pontuacao', 'estrutura_sem_resultado', 'confianca_ina', 'confianca_idaps', 'detalhes',
])]
class IndiceMunicipio extends Model
{
    /** @use HasFactory<IndiceMunicipioFactory> */
    use HasFactory;

    protected $table = 'indices_municipio';

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
            'competencia' => 'integer',
            'metodologia_id' => 'integer',
            'ina' => 'float',
            'idaps' => 'float',
            'idaps_estrutura' => 'float',
            'idaps_resultado' => 'float',
            'ipf_quadrante' => QuadranteIpf::class,
            'ipf_pontuacao' => 'float',
            'estrutura_sem_resultado' => 'boolean',
            'confianca_ina' => 'float',
            'confianca_idaps' => 'float',
            'detalhes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Municipio, $this>
     */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class, 'municipio_id');
    }

    /**
     * @return BelongsTo<Metodologia, $this>
     */
    public function metodologia(): BelongsTo
    {
        return $this->belongsTo(Metodologia::class, 'metodologia_id');
    }
}
