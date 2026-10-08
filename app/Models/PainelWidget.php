<?php

namespace App\Models;

use App\Enums\TipoDeVisual;
use Database\Factories\PainelWidgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'tipo', 'indicador', 'posicao', 'largura'])]
class PainelWidget extends Model
{
    /** @use HasFactory<PainelWidgetFactory> */
    use HasFactory;

    public const LARGURA_MINIMA = 1;

    public const LARGURA_MAXIMA = 3;

    protected $table = 'painel_widgets';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'posicao' => 0,
        'largura' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoDeVisual::class,
            'posicao' => 'integer',
            'largura' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
