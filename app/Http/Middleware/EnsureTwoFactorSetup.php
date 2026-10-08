<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->needsTwoFactorSetup()) {
            return $next($request);
        }

        if ($request->routeIs('conta.seguranca', 'logout')) {
            return $next($request);
        }

        return redirect()->route('conta.seguranca')
            ->with('aviso', 'Seu perfil exige verificação em duas etapas. Ative-a para continuar.');
    }
}
