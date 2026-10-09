<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quem entra com uma senha temporária (definida por um administrador) só usa o sistema depois de trocá-la.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->deve_alterar_senha) {
            return $next($request);
        }

        if ($request->routeIs('conta.seguranca', 'logout')) {
            return $next($request);
        }

        return redirect()->route('conta.seguranca')
            ->with('aviso', 'Você entrou com uma senha temporária. Defina uma nova senha para continuar.');
    }
}
