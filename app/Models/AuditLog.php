<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'evento', 'ip', 'user_agent', 'dados'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    /**
     * Registros mais antigos que o prazo de retenção (`aps.auditoria.retencao_dias`) são apagados pelo `model:prune` diário.
     *
     * @return Builder<AuditLog>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(max(30, (int) config('aps.auditoria.retencao_dias'))));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dados' => 'array',
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
