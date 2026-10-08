<?php

namespace App\Models;

use Database\Factories\MunicipioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'id', 'codigo6', 'nome', 'nome_busca', 'uf', 'codigo_uf',
    'regiao_imediata_codigo', 'regiao_imediata_nome',
    'regiao_saude_codigo', 'regiao_saude_nome',
    'macrorregiao_saude_codigo', 'macrorregiao_saude_nome',
    'piloto', 'ativo',
])]
class Municipio extends Model
{
    /** @use HasFactory<MunicipioFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'piloto' => 'boolean',
            'ativo' => 'boolean',
        ];
    }

    /**
     * Texto em minúsculas e sem acentos, usado na busca por nome.
     */
    public static function normalizarNome(string $nome): string
    {
        return Str::lower(Str::ascii($nome));
    }

    /**
     * @param  Builder<Municipio>  $query
     */
    public function scopePiloto(Builder $query): void
    {
        $query->where('piloto', true);
    }

    /**
     * @param  Builder<Municipio>  $query
     */
    public function scopeAtivo(Builder $query): void
    {
        $query->where('ativo', true);
    }

    /**
     * @param  Builder<Municipio>  $query
     */
    public function scopeBuscarPorNome(Builder $query, string $termo): void
    {
        $query->where('nome_busca', 'like', '%'.addcslashes(self::normalizarNome($termo), '%_\\').'%');
    }

    /**
     * @return HasMany<ValorIndicador, $this>
     */
    public function valores(): HasMany
    {
        return $this->hasMany(ValorIndicador::class, 'municipio_id');
    }
}
