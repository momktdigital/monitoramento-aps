<?php

namespace App\Administracao;

use App\Enums\Perfil;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operações de administração sobre contas de usuário. Toda regra de proteção fica aqui (e não na tela), para valer
 * igual em qualquer lugar que chame o serviço, e toda operação deixa registro na auditoria (nunca com senhas).
 */
class ServicoDeUsuarios
{
    /**
     * @param  array{name: string, email: string, perfil: Perfil|string, municipio_id?: int|null}  $dados
     * @param  string|null  $senha  senha escolhida pelo administrador; sem ela, gera uma temporária
     * @return array{usuario: User, senha: string|null} a senha só é devolvida quando foi gerada aqui (para ser mostrada uma única vez)
     */
    public function criar(User $autor, array $dados, ?string $senha = null): array
    {
        $email = Str::lower(trim($dados['email']));
        $perfil = $dados['perfil'] instanceof Perfil ? $dados['perfil'] : Perfil::from($dados['perfil']);

        $this->exigirEmailLivre($email);

        $gerada = $senha === null ? GeradorDeSenha::temporaria() : null;

        $usuario = User::create([
            'name' => trim($dados['name']),
            'email' => $email,
            'password' => $senha ?? $gerada,
            'perfil' => $perfil,
            'ativo' => true,
            'municipio_id' => $dados['municipio_id'] ?? null,
            'deve_alterar_senha' => true,
            'criado_por_id' => $autor->id,
        ]);

        Auditoria::registrar('usuario_criado', ['alvo_id' => $usuario->id, 'email' => $email, 'perfil' => $perfil->value, 'senha' => $gerada === null ? 'definida' : 'temporaria'], $autor->id);

        return ['usuario' => $usuario, 'senha' => $gerada];
    }

    /**
     * Atualiza nome, e-mail, perfil, município padrão e situação da conta. Só os campos informados mudam.
     *
     * @param  array{name?: string, email?: string, perfil?: Perfil|string, municipio_id?: int|null, ativo?: bool}  $dados
     */
    public function atualizar(User $autor, User $alvo, array $dados): User
    {
        $novoPerfil = isset($dados['perfil']) ? ($dados['perfil'] instanceof Perfil ? $dados['perfil'] : Perfil::from($dados['perfil'])) : $alvo->perfil;
        $novoAtivo = $dados['ativo'] ?? $alvo->ativo;
        $novoEmail = isset($dados['email']) ? Str::lower(trim($dados['email'])) : $alvo->email;

        $mudaPerfil = $novoPerfil !== $alvo->perfil;
        $desativa = $alvo->ativo && ! $novoAtivo;

        if ($alvo->is($autor) && ($mudaPerfil || $desativa)) {
            throw new OperacaoNaoPermitida($desativa
                ? 'Você não pode desativar a sua própria conta.'
                : 'Você não pode alterar o seu próprio perfil. Peça a outro administrador.');
        }

        if (($mudaPerfil && $alvo->perfil === Perfil::Admin) || $desativa) {
            $this->exigirOutroAdministradorAtivo($alvo, $desativa ? 'desativar' : 'rebaixar');
        }

        if ($novoEmail !== $alvo->email) {
            $this->exigirEmailLivre($novoEmail, $alvo);
        }

        $alteracoes = [];

        foreach ([
            'nome' => [$alvo->name, isset($dados['name']) ? trim($dados['name']) : $alvo->name],
            'email' => [$alvo->email, $novoEmail],
            'perfil' => [$alvo->perfil->value, $novoPerfil->value],
            'municipio_id' => [$alvo->municipio_id, array_key_exists('municipio_id', $dados) ? $dados['municipio_id'] : $alvo->municipio_id],
            'ativo' => [$alvo->ativo, (bool) $novoAtivo],
        ] as $campo => [$de, $para]) {
            if ($de !== $para) {
                $alteracoes[$campo] = ['de' => $de, 'para' => $para];
            }
        }

        if ($alteracoes === []) {
            return $alvo;
        }

        DB::transaction(function () use ($alvo, $novoPerfil, $novoEmail, $novoAtivo, $dados, $desativa): void {
            $alvo->forceFill([
                'name' => isset($dados['name']) ? trim($dados['name']) : $alvo->name,
                'email' => $novoEmail,
                'perfil' => $novoPerfil,
                'municipio_id' => array_key_exists('municipio_id', $dados) ? $dados['municipio_id'] : $alvo->municipio_id,
                'ativo' => (bool) $novoAtivo,
            ])->save();

            if ($desativa) {
                $this->revogarAcessos($alvo);
            }
        });

        Auditoria::registrar($desativa ? 'usuario_desativado' : (isset($alteracoes['ativo']) ? 'usuario_ativado' : 'usuario_atualizado'), ['alvo_id' => $alvo->id, 'alteracoes' => $alteracoes], $autor->id);

        return $alvo;
    }

    /**
     * Gera uma nova senha temporária, exige a troca no próximo acesso e encerra as sessões abertas.
     */
    public function redefinirSenha(User $autor, User $alvo): string
    {
        $this->exigirOutraConta($autor, $alvo, 'Para trocar a sua própria senha use a tela Segurança da conta.');

        $senha = GeradorDeSenha::temporaria();

        DB::transaction(function () use ($alvo, $senha): void {
            $alvo->forceFill(['password' => $senha, 'deve_alterar_senha' => true])->save();
            $this->revogarAcessos($alvo);
        });

        Auditoria::registrar('usuario_senha_redefinida', ['alvo_id' => $alvo->id], $autor->id);

        return $senha;
    }

    /**
     * Remove o segundo fator (por exemplo, quando a pessoa perdeu o celular). Administradores terão de configurá-lo de
     * novo no próximo acesso. As sessões abertas são encerradas.
     */
    public function redefinirDoisFatores(User $autor, User $alvo): void
    {
        $this->exigirOutraConta($autor, $alvo, 'Para refazer a sua própria verificação em duas etapas use a tela Segurança da conta.');

        DB::transaction(function () use ($alvo): void {
            $alvo->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
            $this->revogarAcessos($alvo);
        });

        Auditoria::registrar('usuario_dois_fatores_redefinido', ['alvo_id' => $alvo->id], $autor->id);
    }

    /**
     * Derruba todas as sessões abertas do usuário. Devolve quantas existiam.
     */
    public function encerrarSessoes(User $autor, User $alvo): int
    {
        $this->exigirOutraConta($autor, $alvo, 'Para encerrar a sua própria sessão use o botão Sair.');

        $encerradas = $this->sessoesAtivas($alvo);

        $this->revogarAcessos($alvo);

        Auditoria::registrar('usuario_sessoes_encerradas', ['alvo_id' => $alvo->id, 'quantidade' => $encerradas], $autor->id);

        return $encerradas;
    }

    /**
     * Exclui a conta e o painel dela. O histórico de auditoria permanece (sem vínculo com a conta).
     */
    public function excluir(User $autor, User $alvo): void
    {
        $this->exigirOutraConta($autor, $alvo, 'Você não pode excluir a sua própria conta.');
        $this->exigirOutroAdministradorAtivo($alvo, 'excluir');

        Auditoria::registrar('usuario_excluido', ['alvo_id' => $alvo->id, 'email' => $alvo->email, 'perfil' => $alvo->perfil->value], $autor->id);

        DB::transaction(function () use ($alvo): void {
            $this->revogarAcessos($alvo);
            $alvo->delete();
        });
    }

    /**
     * Sessões do usuário que ainda não expiraram.
     */
    public function sessoesAtivas(User $usuario): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $usuario->id)
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->count();
    }

    /**
     * Apaga as sessões e o "lembrar de mim": o usuário precisa entrar de novo.
     */
    private function revogarAcessos(User $usuario): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $usuario->id)->delete();
        }

        $usuario->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    private function exigirOutraConta(User $autor, User $alvo, string $mensagem): void
    {
        if ($alvo->is($autor)) {
            throw new OperacaoNaoPermitida($mensagem);
        }
    }

    private function exigirEmailLivre(string $email, ?User $exceto = null): void
    {
        $existe = User::query()
            ->where('email', $email)
            ->when($exceto !== null, fn ($consulta) => $consulta->whereKeyNot($exceto->id))
            ->exists();

        if ($existe) {
            throw new OperacaoNaoPermitida('Já existe um usuário com esse e-mail.');
        }
    }

    /**
     * Garante que o sistema nunca fique sem administrador ativo: se o alvo é administrador ativo, precisa existir outro.
     */
    private function exigirOutroAdministradorAtivo(User $alvo, string $acao): void
    {
        if ($alvo->perfil !== Perfil::Admin || ! $alvo->ativo) {
            return;
        }

        $outros = User::query()->where('perfil', Perfil::Admin->value)->where('ativo', true)->whereKeyNot($alvo->id)->count();

        if ($outros === 0) {
            throw new OperacaoNaoPermitida("Este é o único administrador ativo: não é possível {$acao} a conta. Crie ou ative outro administrador antes.");
        }
    }
}
