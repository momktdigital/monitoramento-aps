<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class Auditoria
{
    /**
     * Registra um evento de segurança/operação. Nunca inclua senhas, chaves ou tokens em $dados.
     *
     * @param  array<string, mixed>  $dados
     */
    public static function registrar(string $evento, array $dados = [], ?int $userId = null): void
    {
        $request = request();

        AuditLog::create([
            'user_id' => $userId ?? Auth::id(),
            'evento' => $evento,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'dados' => $dados === [] ? null : $dados,
        ]);
    }
}
