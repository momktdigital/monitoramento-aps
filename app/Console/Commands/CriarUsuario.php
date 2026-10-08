<?php

namespace App\Console\Commands;

use App\Enums\Perfil;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('aps:criar-usuario {--perfil= : admin, gestor ou leitura}')]
#[Description('Cria um usuário (a senha é solicitada de forma oculta; não há usuário padrão)')]
class CriarUsuario extends Command
{
    public function handle(): int
    {
        $perfil = Perfil::tryFrom((string) $this->option('perfil')) ?? Perfil::from(select(
            'Perfil do usuário',
            collect(Perfil::cases())->mapWithKeys(fn (Perfil $perfil) => [$perfil->value => $perfil->rotulo()])->all(),
            default: Perfil::Leitura->value,
        ));

        $nome = text('Nome completo', required: true);
        $email = text('E-mail', required: true, validate: fn (string $valor) => Validator::make(
            ['email' => $valor],
            ['email' => ['email', 'max:255', Rule::unique(User::class, 'email')]],
        )->errors()->first('email') ?: null);
        $senha = password('Senha (mín. 12 caracteres, com maiúsculas, minúsculas, números e símbolos)', required: true, validate: fn (string $valor) => Validator::make(
            ['senha' => $valor],
            ['senha' => [Password::default()]],
            attributes: ['senha' => 'senha'],
        )->errors()->first('senha') ?: null);

        User::create([
            'name' => $nome,
            'email' => mb_strtolower($email),
            'password' => $senha,
            'perfil' => $perfil,
            'ativo' => true,
        ]);

        $this->components->info("Usuário criado ({$perfil->rotulo()}).".($perfil->exigeDoisFatores() ? ' Ele será obrigado a ativar a verificação em duas etapas no primeiro acesso.' : ''));

        return self::SUCCESS;
    }
}
