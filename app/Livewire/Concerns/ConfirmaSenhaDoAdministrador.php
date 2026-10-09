<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Pede a senha de quem está operando de novo antes de ações que mudam acessos ou resultados, com limite de tentativas.
 * A tela precisa ter a propriedade pública `$senhaAtual` ligada ao campo de senha.
 */
trait ConfirmaSenhaDoAdministrador
{
    public string $senhaAtual = '';

    private function confirmarSenhaAtual(): bool
    {
        $this->resetErrorBag('senhaAtual');

        $chave = 'admin-confirmar-senha:'.Auth::id();

        if (RateLimiter::tooManyAttempts($chave, 5)) {
            $this->addError('senhaAtual', 'Muitas tentativas. Aguarde '.RateLimiter::availableIn($chave).' segundos e tente de novo.');

            return false;
        }

        if ($this->senhaAtual === '' || ! Hash::check($this->senhaAtual, Auth::user()->getAuthPassword())) {
            RateLimiter::hit($chave, 60);
            $this->addError('senhaAtual', 'A sua senha não confere.');

            return false;
        }

        RateLimiter::clear($chave);
        $this->senhaAtual = '';

        return true;
    }
}
