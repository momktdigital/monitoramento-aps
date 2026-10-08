<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AutenticacaoTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'Senha-Forte-123!';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login');
    }

    private function usuario(array $atributos = [], string $estado = 'gestor'): User
    {
        return User::factory()->{$estado}()->create($atributos + ['password' => self::SENHA]);
    }

    public function test_a_tela_de_login_e_exibida(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Acesse sua conta');
    }

    public function test_visitante_e_redirecionado_ao_login(): void
    {
        $this->get(route('painel'))->assertRedirect(route('login'));
        $this->get('/')->assertRedirect('/painel');
    }

    public function test_usuario_ativo_entra_com_credenciais_corretas(): void
    {
        $usuario = $this->usuario();

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => self::SENHA])
            ->assertRedirect(route('painel'));

        $this->assertAuthenticatedAs($usuario);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $usuario->id, 'evento' => 'login']);
    }

    public function test_senha_incorreta_nao_autentica_e_e_auditada(): void
    {
        $usuario = $this->usuario();

        $this->from(route('login'))
            ->post(route('login.store'), ['email' => $usuario->email, 'password' => 'errada'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['evento' => 'login_falhou']);
    }

    public function test_usuario_inativo_nao_entra_mesmo_com_senha_correta(): void
    {
        $usuario = $this->usuario(['ativo' => false]);

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => self::SENHA])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_email_inexistente_recebe_a_mesma_mensagem_de_senha_incorreta(): void
    {
        $usuario = $this->usuario();

        $mensagem = ['email' => 'E-mail ou senha incorretos.'];

        $this->post(route('login.store'), ['email' => 'ninguem@exemplo.com', 'password' => 'qualquer'])
            ->assertSessionHasErrors($mensagem);

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => 'qualquer'])
            ->assertSessionHasErrors($mensagem);
    }

    public function test_apos_cinco_tentativas_o_login_e_bloqueado_mesmo_com_a_senha_certa(): void
    {
        $usuario = $this->usuario();

        foreach (range(1, 5) as $tentativa) {
            $this->post(route('login.store'), ['email' => $usuario->email, 'password' => 'errada']);
        }

        $this->post(route('login.store'), ['email' => $usuario->email, 'password' => self::SENHA])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['evento' => 'login_bloqueado']);
    }

    public function test_nao_existe_cadastro_publico_nem_recuperacao_de_senha_por_email(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
    }

    public function test_logout_encerra_a_sessao_e_e_auditado(): void
    {
        $usuario = $this->usuario();

        $this->actingAs($usuario)->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();
        $this->assertSame(1, AuditLog::where('evento', 'logout')->count());
    }
}
