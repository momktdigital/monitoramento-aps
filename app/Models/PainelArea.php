<?php

namespace App\Models;

use Database\Factories\PainelAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Área (aba) do painel de um usuário: agrupa os visuais de um assunto. A área `padrao` é a que abre primeiro.
 * `modelo` indica de qual área pronta ela nasceu (nula nas criadas do zero), o que permite restaurá-la.
 */
#[Fillable(['user_id', 'nome', 'posicao', 'padrao', 'modelo'])]
class PainelArea extends Model
{
    /** @use HasFactory<PainelAreaFactory> */
    use HasFactory;

    public const NOME_MAXIMO = 40;

    protected $table = 'painel_areas';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'posicao' => 0,
        'padrao' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posicao' => 'integer',
            'padrao' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PainelWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(PainelWidget::class, 'area_id')->orderBy('posicao')->orderBy('id');
    }
}
