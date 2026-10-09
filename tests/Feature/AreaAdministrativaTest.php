<?php

namespace Tests\Feature;

use App\Livewire\Conta\Seguranca;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class AreaAdministrativaTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'Senha-Forte-123!';

    /**
     * @return list<string>
     */
    private function rotas(): array
    {
        return ['admin.inicio', 'admin.usuarios', 'admin.integracoes', 'admin.municipios', 'admin.indicadores', 'admin.indices', 'admin.auditoria', 'admin.sistema', 'admin.auditoria.exportar'];
    }

    public function test_visitante_vai_para_o_login_em_todas_as_telas(): void
    {
        foreach ($this->rotas() as $rota) {
            $this->get(route($rota))->assertRedirect(route('login'));
        }
    }

    public function test_so_administrador_ativo_entra_na_administracao(): void
    {
        $permitido = User::factory()->admin()->comDoisFatores()->create();
        $inativo = User::factory()->admin()->comDoisFatores()->inativo()->create();

        foreach ($this->rotas() as $rota) {
            $this->actingAs(User::factory()->create())->get(route($rota))->assertForbidden();
            $this->actingAs(User::factory()->gestor()->create())->get(route($rota))->assertForbidden();
            $this->actingAs($inativo)->get(route($rota))->assertForbidden();
            $this->actingAs($permitido)->get(route($rota))->assertOk();
        }
    }

    public function test_administrador_sem_dois_fatores_e_levado_a_configura_los(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.usuarios'))->assertRedirect(route('conta.seguranca'));
    }

    public function test_a_navegacao_da_administracao_lista_todas_as_secoes(): void
    {
        $this->actingAs(User::factory()->admin()->comDoisFatores()->create())
            ->get(route('admin.inicio'))
            ->assertSee(['Visão geral', 'Usuários', 'Integrações', 'Municípios', 'Indicadores', 'Índices', 'Auditoria', 'Sistema'])
            ->assertSee('aria-current="page"', false);
    }

    public function test_senha_temporaria_obriga_a_trocar_antes_de_usar_o_sistema(): void
    {
        $usuario = User::factory()->gestor()->comSenhaTemporaria()->create(['password' => self::SENHA]);

        $this->actingAs($usuario)->get(route('painel'))->assertRedirect(route('conta.seguranca'));
        $this->actingAs($usuario)->get(route('comparar'))->assertRedirect(route('conta.seguranca'));
        $this->actingAs($usuario)->get(route('conta.seguranca'))->assertOk()->assertSee('senha temporária');
    }

    public function test_trocar_a_senha_libera_o_acesso(): void
    {
        $usuario = User::factory()->gestor()->comSenhaTemporaria()->create(['password' => self::SENHA]);

        Livewire::actingAs($usuario)->test(Seguranca::class)
            ->set('current_password', self::SENHA)
            ->set('password', 'Outra-Senha-Forte-456!')
            ->set('password_confirmation', 'Outra-Senha-Forte-456!')
            ->call('alterarSenha')
            ->assertHasNoErrors()
            ->assertRedirect(route('painel'));

        $this->assertFalse($usuario->fresh()->deve_alterar_senha);
        $this->actingAs($usuario->fresh())->get(route('painel'))->assertOk();
    }

    public function test_o_login_registra_data_e_ip_do_ultimo_acesso(): void
    {
        RateLimiter::clear('login');
        $usuario = User::factory()->gestor()->create(['password' => self::SENHA]);

        $this->assertNull($usuario->ultimo_acesso_em);

        $this->post(route('login'), ['email' => $usuario->email, 'password' => self::SENHA])->assertRedirect();

        $usuario->refresh();
        $this->assertNotNull($usuario->ultimo_acesso_em);
        $this->assertNotNull($usuario->ultimo_acesso_ip);
        $this->assertTrue(AuditLog::where('evento', 'login')->where('user_id', $usuario->id)->exists());
    }

    public function test_o_menu_principal_mostra_administracao_so_para_administradores(): void
    {
        $this->actingAs(User::factory()->admin()->comDoisFatores()->create())->get(route('painel'))->assertSee('Administração');
        $this->actingAs(User::factory()->gestor()->create())->get(route('painel'))->assertDontSee('Administração');
    }
}
