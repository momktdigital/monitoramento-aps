<?php

namespace App\Listeners;

use App\Support\Auditoria;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

class AuditarAutenticacao
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, function (Login $e): void {
            Auditoria::registrar('login', [], $e->user->getAuthIdentifier());

            // Guarda o último acesso para a administração de usuários (sem disparar eventos de modelo).
            $e->user->forceFill(['ultimo_acesso_em' => now(), 'ultimo_acesso_ip' => request()->ip()])->saveQuietly();
        });
        $events->listen(Logout::class, fn (Logout $e) => Auditoria::registrar('logout', [], $e->user?->getAuthIdentifier()));
        $events->listen(Failed::class, fn (Failed $e) => Auditoria::registrar('login_falhou', [
            'email' => Str::limit(Str::lower((string) ($e->credentials['email'] ?? '')), 120, ''),
        ], $e->user?->getAuthIdentifier()));
        $events->listen(Lockout::class, fn (Lockout $e) => Auditoria::registrar('login_bloqueado', [
            'email' => Str::limit(Str::lower((string) $e->request->input('email')), 120, ''),
        ]));
        $events->listen(TwoFactorAuthenticationFailed::class, fn ($e) => Auditoria::registrar('dois_fatores_falhou', [], $e->user->getAuthIdentifier()));
        $events->listen(TwoFactorAuthenticationEnabled::class, fn ($e) => Auditoria::registrar('dois_fatores_iniciado', [], $e->user->getAuthIdentifier()));
        $events->listen(TwoFactorAuthenticationConfirmed::class, fn ($e) => Auditoria::registrar('dois_fatores_ativado', [], $e->user->getAuthIdentifier()));
        $events->listen(TwoFactorAuthenticationDisabled::class, fn ($e) => Auditoria::registrar('dois_fatores_desativado', [], $e->user->getAuthIdentifier()));
        $events->listen(RecoveryCodesGenerated::class, fn ($e) => Auditoria::registrar('codigos_recuperacao_gerados', [], $e->user->getAuthIdentifier()));
        $events->listen(PasswordUpdatedViaController::class, fn ($e) => Auditoria::registrar('senha_alterada', [], $e->user->getAuthIdentifier()));
    }
}
