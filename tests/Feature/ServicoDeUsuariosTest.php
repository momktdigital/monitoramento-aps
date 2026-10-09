<?php

namespace Tests\Feature;

use App\Administracao\GeradorDeSenha;
use App\Administracao\OperacaoNaoPermitida;
use App\Administracao\ServicoDeUsuarios;
use App\Enums\Perfil;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class ServicoDeUsuariosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServicoDeUsuarios $servico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->comDoisFatores()->create();
        $this->servico = app(ServicoDeUsuarios::class);
    }

    public function test_senhas_geradas_atendem_a_politica_do_sistema(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $senha = GeradorDeSenha::temporaria();

            $this->assertGreaterThanOrEqual(12, strlen($senha));
            $this->assertMatchesRegularExpression('/[A-Z]/', $senha);
            $this->assertMatchesRegularExpression('/[a-z]/', $senha);
            $this->assertMatchesRegularExpression('/\d/', $senha);
            $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $senha);
            $this->assertDoesNotMatchRegularExpression('/[0OIl1]/', $senha);
        }

        $this->assertNotSame(GeradorDeSenha::temporaria(), GeradorDeSenha::temporaria());
        $this->assertGreaterThanOrEqual(12, strlen(GeradorDeSenha::temporaria(4)));
    }

    public function test_cria_usuario_com_senha_temporaria_e_exige_troca(): void
    {
        $resultado = $this->servico->criar($this->admin, ['name' => ' Maria Souza ', 'email' => ' Maria@Exemplo.COM ', 'perfil' => 'gestor']);

        $usuario = $resultado['usuario']->fresh();

        $this->assertSame('Maria Souza', $usuario->name);
        $this->assertSame('maria@exemplo.com', $usuario->email);
        $this->assertSame(Perfil::Gestor, $usuario->perfil);
        $this->assertTrue($usuario->ativo);
        $this->assertTrue($usuario->deve_alterar_senha);
        $this->assertSame($this->admin->id, $usuario->criado_por_id);
        $this->assertNotNull($resultado['senha']);
        $this->assertTrue(Hash::check($resultado['senha'], $usuario->password));
        $this->assertTrue(Validator::make(['senha' => $resultado['senha']], ['senha' => [Password::default()]])->passes());

        $registro = AuditLog::where('evento', 'usuario_criado')->firstOrFail();
        $this->assertSame($this->admin->id, $registro->user_id);
        $this->assertStringNotContainsString($resultado['senha'], json_encode($registro->dados));
    }

    public function test_cria_usuario_com_senha_escolhida_sem_devolve_la(): void
    {
        $resultado = $this->servico->criar($this->admin, ['name' => 'Ana', 'email' => 'ana@exemplo.com', 'perfil' => Perfil::Leitura], 'Minha-Senha-Forte-9!');

        $this->assertNull($resultado['senha']);
        $this->assertTrue(Hash::check('Minha-Senha-Forte-9!', $resultado['usuario']->password));
        $this->assertTrue($resultado['usuario']->deve_alterar_senha);
    }

    public function test_nao_cria_usuario_com_email_repetido(): void
    {
        User::factory()->create(['email' => 'repetido@exemplo.com']);

        $this->expectException(OperacaoNaoPermitida::class);

        $this->servico->criar($this->admin, ['name' => 'Outra', 'email' => 'REPETIDO@exemplo.com', 'perfil' => 'leitura']);
    }

    public function test_atualiza_dados_e_registra_so_o_que_mudou(): void
    {
        $alvo = User::factory()->create(['name' => 'Antigo', 'perfil' => Perfil::Leitura]);

        $this->servico->atualizar($this->admin, $alvo, ['name' => 'Novo', 'perfil' => 'gestor']);

        $this->assertSame('Novo', $alvo->fresh()->name);
        $this->assertSame(Perfil::Gestor, $alvo->fresh()->perfil);

        $registro = AuditLog::where('evento', 'usuario_atualizado')->firstOrFail();
        $this->assertSame(['nome', 'perfil'], array_keys($registro->dados['alteracoes']));
    }

    public function test_sem_alteracao_nao_gera_registro(): void
    {
        $alvo = User::factory()->create();

        $this->servico->atualizar($this->admin, $alvo, ['name' => $alvo->name, 'email' => $alvo->email]);

        $this->assertSame(0, AuditLog::where('evento', 'usuario_atualizado')->count());
    }

    public function test_ninguem_muda_o_proprio_perfil_nem_se_desativa(): void
    {
        $outroAdmin = User::factory()->admin()->create();

        try {
            $this->servico->atualizar($this->admin, $this->admin, ['perfil' => 'gestor']);
            $this->fail('Deveria recusar a mudança do próprio perfil.');
        } catch (OperacaoNaoPermitida $erro) {
            $this->assertStringContainsString('próprio perfil', $erro->getMessage());
        }

        try {
            $this->servico->atualizar($this->admin, $this->admin, ['ativo' => false]);
            $this->fail('Deveria recusar a própria desativação.');
        } catch (OperacaoNaoPermitida $erro) {
            $this->assertStringContainsString('desativar a sua própria conta', $erro->getMessage());
        }

        $this->assertTrue($outroAdmin->fresh()->ativo);
    }

    public function test_o_ultimo_administrador_ativo_e_protegido(): void
    {
        $unico = $this->admin;
        $autor = User::factory()->admin()->inativo()->create(); // administrador inativo não conta como alternativa

        foreach ([['perfil' => 'gestor'], ['ativo' => false]] as $dados) {
            try {
                $this->servico->atualizar($autor, $unico, $dados);
                $this->fail('Deveria proteger o último administrador.');
            } catch (OperacaoNaoPermitida $erro) {
                $this->assertStringContainsString('único administrador ativo', $erro->getMessage());
            }
        }

        try {
            $this->servico->excluir($autor, $unico);
            $this->fail('Deveria proteger o último administrador.');
        } catch (OperacaoNaoPermitida) {
            $this->assertTrue(User::whereKey($unico->id)->exists());
        }

        User::factory()->admin()->create();
        $this->servico->atualizar($autor, $unico, ['perfil' => 'gestor']);
        $this->assertSame(Perfil::Gestor, $unico->fresh()->perfil);
    }

    public function test_desativar_encerra_sessoes_e_reativar_libera(): void
    {
        config(['session.driver' => 'database']);
        $alvo = User::factory()->create(['remember_token' => 'antigo']);
        $this->criarSessao($alvo);

        $this->servico->atualizar($this->admin, $alvo, ['ativo' => false]);

        $this->assertFalse($alvo->fresh()->ativo);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $alvo->id)->count());
        $this->assertNotSame('antigo', $alvo->fresh()->remember_token);
        $this->assertTrue(AuditLog::where('evento', 'usuario_desativado')->exists());

        $this->servico->atualizar($this->admin, $alvo, ['ativo' => true]);

        $this->assertTrue($alvo->fresh()->ativo);
        $this->assertTrue(AuditLog::where('evento', 'usuario_ativado')->exists());
    }

    public function test_redefinir_senha_gera_nova_forca_troca_e_derruba_sessoes(): void
    {
        config(['session.driver' => 'database']);
        $alvo = User::factory()->create(['password' => 'Senha-Antiga-123!']);
        $this->criarSessao($alvo);

        $nova = $this->servico->redefinirSenha($this->admin, $alvo);

        $alvo->refresh();
        $this->assertTrue(Hash::check($nova, $alvo->password));
        $this->assertFalse(Hash::check('Senha-Antiga-123!', $alvo->password));
        $this->assertTrue($alvo->deve_alterar_senha);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $alvo->id)->count());
        $this->assertStringNotContainsString($nova, json_encode(AuditLog::where('evento', 'usuario_senha_redefinida')->firstOrFail()->dados));
    }

    public function test_redefinir_dois_fatores_limpa_segredo_e_codigos(): void
    {
        $alvo = User::factory()->admin()->comDoisFatores()->create();

        $this->servico->redefinirDoisFatores($this->admin, $alvo);

        $alvo->refresh();
        $this->assertNull($alvo->two_factor_secret);
        $this->assertNull($alvo->two_factor_recovery_codes);
        $this->assertNull($alvo->two_factor_confirmed_at);
        $this->assertTrue($alvo->needsTwoFactorSetup());
    }

    public function test_encerrar_sessoes_informa_quantas_existiam(): void
    {
        config(['session.driver' => 'database']);
        $alvo = User::factory()->create();
        $this->criarSessao($alvo);
        $this->criarSessao($alvo);

        $this->assertSame(2, $this->servico->sessoesAtivas($alvo));
        $this->assertSame(2, $this->servico->encerrarSessoes($this->admin, $alvo));
        $this->assertSame(0, $this->servico->sessoesAtivas($alvo));
    }

    public function test_operacoes_sobre_a_propria_conta_sao_recusadas(): void
    {
        User::factory()->admin()->create();

        foreach ([
            fn () => $this->servico->redefinirSenha($this->admin, $this->admin),
            fn () => $this->servico->redefinirDoisFatores($this->admin, $this->admin),
            fn () => $this->servico->encerrarSessoes($this->admin, $this->admin),
            fn () => $this->servico->excluir($this->admin, $this->admin),
        ] as $operacao) {
            try {
                $operacao();
                $this->fail('Deveria recusar a operação sobre a própria conta.');
            } catch (OperacaoNaoPermitida) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue(User::whereKey($this->admin->id)->exists());
    }

    public function test_excluir_remove_a_conta_e_mantem_o_historico(): void
    {
        $alvo = User::factory()->create();
        AuditLog::factory()->create(['user_id' => $alvo->id, 'evento' => 'login']);

        $this->servico->excluir($this->admin, $alvo);

        $this->assertFalse(User::whereKey($alvo->id)->exists());
        $this->assertSame(1, AuditLog::where('evento', 'login')->count());
        $this->assertNull(AuditLog::where('evento', 'login')->first()->user_id);
        $this->assertTrue(AuditLog::where('evento', 'usuario_excluido')->exists());
    }

    private function criarSessao(User $usuario): void
    {
        DB::table('sessions')->insert([
            'id' => fake()->unique()->sha1(),
            'user_id' => $usuario->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'teste',
            'payload' => 'x',
            'last_activity' => now()->getTimestamp(),
        ]);
    }
}
