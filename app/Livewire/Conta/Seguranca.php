<?php

namespace App\Livewire\Conta;

use App\Actions\Fortify\UpdateUserPassword;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('Segurança da conta')]
class Seguranca extends Component
{
    public string $codigoConfirmacao = '';

    public string $senhaParaAlterarDoisFatores = '';

    #[Locked]
    public bool $exibirCodigosRecuperacao = false;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function iniciarDoisFatores(EnableTwoFactorAuthentication $enable): void
    {
        $enable(Auth::user());

        $this->reset('codigoConfirmacao');
        $this->exibirCodigosRecuperacao = true;
    }

    public function confirmarDoisFatores(ConfirmTwoFactorAuthentication $confirm): void
    {
        $this->validate(['codigoConfirmacao' => ['required', 'string', 'max:10']], attributes: ['codigoConfirmacao' => 'código']);

        try {
            $confirm(Auth::user(), $this->codigoConfirmacao);
        } catch (ValidationException $e) {
            $this->addError('codigoConfirmacao', 'Código inválido. Confira o aplicativo e tente novamente.');

            return;
        }

        $this->reset('codigoConfirmacao');
        $this->exibirCodigosRecuperacao = true;
        session()->flash('sucesso', 'Verificação em duas etapas ativada. Guarde seus códigos de recuperação abaixo.');
    }

    public function gerarNovosCodigosRecuperacao(GenerateNewRecoveryCodes $generate): void
    {
        $this->confirmarSenhaAtual();

        $generate(Auth::user());

        $this->exibirCodigosRecuperacao = true;
        $this->reset('senhaParaAlterarDoisFatores');
    }

    public function desativarDoisFatores(DisableTwoFactorAuthentication $disable): void
    {
        $user = Auth::user();

        if ($user->perfil->exigeDoisFatores()) {
            $this->addError('senhaParaAlterarDoisFatores', 'O seu perfil exige a verificação em duas etapas e ela não pode ser desativada.');

            return;
        }

        $this->confirmarSenhaAtual();

        $disable($user);

        $this->reset('senhaParaAlterarDoisFatores', 'exibirCodigosRecuperacao');
    }

    public function alterarSenha(UpdateUserPassword $update): void
    {
        $user = Auth::user();
        $eraSenhaTemporaria = $user->deve_alterar_senha;

        $update->update($user, [
            'current_password' => $this->current_password,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ]);

        Auth::logoutOtherDevices($this->password);
        event(new PasswordUpdatedViaController($user));

        $this->reset('current_password', 'password', 'password_confirmation');
        session()->flash('sucesso', 'Senha alterada com sucesso. Outras sessões foram encerradas.');

        if ($eraSenhaTemporaria) {
            $this->redirectRoute('painel', navigate: true);
        }
    }

    private function confirmarSenhaAtual(): void
    {
        $this->validate(
            ['senhaParaAlterarDoisFatores' => ['required', 'string', 'current_password:web']],
            ['senhaParaAlterarDoisFatores.current_password' => 'A senha informada não confere.'],
            ['senhaParaAlterarDoisFatores' => 'senha'],
        );
    }

    public function render(): View
    {
        $user = Auth::user();

        return view('livewire.conta.seguranca', [
            'doisFatoresAtivo' => $user->hasEnabledTwoFactorAuthentication(),
            'doisFatoresPendente' => $user->two_factor_secret !== null && ! $user->hasEnabledTwoFactorAuthentication(),
            'qrCodeSvg' => $user->two_factor_secret !== null && ! $user->hasEnabledTwoFactorAuthentication() ? $user->twoFactorQrCodeSvg() : null,
            'chaveManual' => $user->two_factor_secret !== null && ! $user->hasEnabledTwoFactorAuthentication() ? decrypt($user->two_factor_secret) : null,
            'codigosRecuperacao' => $this->exibirCodigosRecuperacao && $user->two_factor_secret !== null ? $user->recoveryCodes() : [],
            'obrigatorio' => $user->perfil->exigeDoisFatores(),
        ]);
    }
}
