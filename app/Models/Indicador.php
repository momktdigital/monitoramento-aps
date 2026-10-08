<?php

namespace App\Models;

use App\Enums\Dimensao;
use App\Enums\Periodicidade;
use App\Enums\Polaridade;
use App\Support\Competencia;
use Database\Factories\IndicadorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'codigo', 'nome', 'dimensao', 'fonte', 'unidade', 'polaridade', 'periodicidade',
    'casas_decimais', 'teto', 'ordem', 'ativo', 'visivel',
    'o_que_e', 'como_calcula', 'para_que_serve', 'como_interpretar', 'texto_versao',
])]
class Indicador extends Model
{
    /** @use HasFactory<IndicadorFactory> */
    use HasFactory;

    protected $table = 'indicadores';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimensao' => Dimensao::class,
            'polaridade' => Polaridade::class,
            'periodicidade' => Periodicidade::class,
            'casas_decimais' => 'integer',
            'teto' => 'float',
            'ordem' => 'integer',
            'ativo' => 'boolean',
            'visivel' => 'boolean',
            'texto_versao' => 'integer',
        ];
    }

    /**
     * @param  Builder<Indicador>  $query
     */
    public function scopeAtivo(Builder $query): void
    {
        $query->where('ativo', true);
    }

    /**
     * Indicadores que aparecem para o usuário (os auxiliares ficam ocultos).
     *
     * @param  Builder<Indicador>  $query
     */
    public function scopeVisivel(Builder $query): void
    {
        $query->where('visivel', true);
    }

    /**
     * @param  Builder<Indicador>  $query
     */
    public function scopeDaDimensao(Builder $query, Dimensao $dimensao): void
    {
        $query->where('dimensao', $dimensao->value);
    }

    /**
     * Valor para fins de comparação: acima do teto (quando existe) vale como o próprio teto.
     */
    public function valorAvaliado(float $valor): float
    {
        return $this->teto === null ? $valor : min($valor, $this->teto);
    }

    public function estaNoTeto(float $valor): bool
    {
        return $this->teto !== null && $valor >= $this->teto;
    }

    /**
     * Texto da competência para este indicador: só o ano nos anuais (guardados como AAAA12), mês/ano nos demais.
     */
    public function rotuloDaCompetencia(?int $competencia): string
    {
        if ($competencia === null) {
            return '—';
        }

        return $this->periodicidade === Periodicidade::Anual ? (string) intdiv($competencia, 100) : Competencia::rotulo($competencia);
    }

    /**
     * Nome da fonte oficial, para exibir ao lado do dado.
     */
    public function fonteRotulo(): string
    {
        return match ($this->fonte) {
            'ibge' => 'IBGE',
            'egestor' => 'e-Gestor APS (Ministério da Saúde)',
            'cnes' => 'CNES (Ministério da Saúde)',
            'transparencia' => 'Portal da Transparência',
            'sih' => 'SIH/SUS',
            'sim' => 'SIM (Ministério da Saúde)',
            'sinasc' => 'SINASC (Ministério da Saúde)',
            'sisab' => 'SISAB (Ministério da Saúde)',
            'siops' => 'SIOPS (Ministério da Saúde)',
            'ans' => 'ANS',
            'ipea' => 'IPEA',
            'fns' => 'Fundo Nacional de Saúde',
            'calculado' => 'Cálculo da plataforma (a partir dos indicadores)',
            default => $this->fonte,
        };
    }

    /**
     * @return HasMany<ValorIndicador, $this>
     */
    public function valores(): HasMany
    {
        return $this->hasMany(ValorIndicador::class, 'indicador_id');
    }
}
