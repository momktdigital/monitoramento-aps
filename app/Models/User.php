<?php

namespace App\Models;

use App\Enums\Perfil;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'perfil', 'ativo', 'municipio_id', 'painel_personalizado'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'perfil' => Perfil::class,
            'ativo' => 'boolean',
            'painel_personalizado' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Município padrão do usuário (abre o painel já filtrado por ele).
     *
     * @return BelongsTo<Municipio, $this>
     */
    public function municipio(): BelongsTo
    {
        return $this->belongsTo(Municipio::class);
    }

    /**
     * @return HasMany<PainelArea, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(PainelArea::class)->orderBy('posicao')->orderBy('id');
    }

    /**
     * @return HasMany<PainelWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(PainelWidget::class)->orderBy('posicao')->orderBy('id');
    }

    public function isAdmin(): bool
    {
        return $this->perfil === Perfil::Admin;
    }

    /**
     * Indica se o usuário precisa configurar a verificação em duas etapas antes de usar o sistema.
     */
    public function needsTwoFactorSetup(): bool
    {
        return $this->perfil->exigeDoisFatores() && ! $this->hasEnabledTwoFactorAuthentication();
    }
}
