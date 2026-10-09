<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Livewire\Admin\Usuarios;
use App\Models\AuditLog;
use App\Models\Municipio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class TelaDeUsuariosTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'Senha-Forte-123!';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->comDoisFatores()->create(['password' => self::SENHA]);
        RateLimiter::clear('admin-confirmar-senha:'.$this->admin->id);
    }

    private function tela(): Testable
    {
        return Livewire::actingAs($this->admin)->test(Usuarios::class);
    }

    public function test_lista_busca_e_filtra_usuarios(): void
    {
        User::factory()->create(['name' => 'Beatriz Lima', 'email' => 'beatriz@exemplo.com', 'perfil' => Perfil::Leitura]);
        User::factory()->gestor()->create(['name' => 'Carlos Dias', 'email' => 'carlos@exemplo.com']);
        User::factory()->inativo()->create(['name' => 'Davi Rocha', 'email' => 'davi@exemplo.com']);
        User::factory()->gestor()->comSenhaTemporaria()->create(['name' => 'Elisa Prado', 'email' => 'elisa@exemplo.com']);

        $this->tela()
            ->assertSee(['Beatriz Lima', 'Carlos Dias', 'Davi Rocha'])
            ->set('busca', 'carlos@')->assertSee('Carlos Dias')->assertDontSee('Beatriz Lima')
            ->set('busca', '')->set('perfil', 'leitura')->assertSee('Beatriz Lima')->assertDontSee('Carlos Dias')
            ->set('perfil', '')->set('situacao', 'inativos')->assertSee('Davi Rocha')->assertDontSee('Carlos Dias')
            ->set('situacao', 'pendentes')->assertSee('Elisa Prado')->assertDontSee('Davi Rocha');
    }

    public function test_a_busca_trata_curingas_como_texto(): void
    {
        User::factory()->create(['name' => 'Fulano Silva']);

        $this->tela()->set('busca', '%')->assertDontSee('Fulano Silva');
    }

    public function test_cria_usuario_com_senha_temporaria_mostrada_uma_unica_vez(): void
    {
        Municipio::factory()->create(['id' => 3306107, 'nome' => 'Valença']);

        $tela = $this->tela()
            ->call('novo')
            ->set('nome', 'Nova Pessoa')
            ->set('email', 'nova@exemplo.com')
            ->set('perfilDoFormulario', 'gestor')
            ->set('municipioId', 3306107)
            ->call('salvar')
            ->assertHasNoErrors()
            ->assertSet('janela', 'senha-gerada')
            ->assertSee('não será mostrada de novo');

        $usuario = User::where('email', 'nova@exemplo.com')->firstOrFail();
        $senha = $tela->get('senhaGerada');

        $this->assertTrue(Hash::check($senha, $usuario->password));
        $this->assertTrue($usuario->deve_alterar_senha);
        $this->assertSame(3306107, $usuario->municipio_id);
        $tela->assertSee($senha);

        $tela->call('fechar')->assertSet('senhaGerada', null)->assertDontSee($senha);
    }

    public function test_cria_usuario_com_senha_definida_pelo_administrador(): void
    {
        $this->tela()
            ->call('novo')
            ->set('nome', 'Pessoa Manual')
            ->set('email', 'manual@exemplo.com')
            ->set('modoDeSenha', 'definir')
            ->set('senha', 'Definida-Por-Mim-77!')
            ->call('salvar')
            ->assertHasNoErrors()
            ->assertSet('janela', null)
            ->assertSet('senhaGerada', null);

        $this->assertTrue(Hash::check('Definida-Por-Mim-77!', User::where('email', 'manual@exemplo.com')->firstOrFail()->password));
    }

    public function test_valida_o_formulario_de_criacao(): void
    {
        User::factory()->create(['email' => 'existe@exemplo.com']);

        $this->tela()->call('novo')->call('salvar')->assertHasErrors(['nome', 'email']);

        $this->tela()->call('novo')->set('nome', 'X')->set('email', 'sem-arroba')->call('salvar')->assertHasErrors(['email']);

        $this->tela()->call('novo')->set('nome', 'X')->set('email', 'a@b.com')->set('modoDeSenha', 'definir')->set('senha', 'fraca')->call('salvar')->assertHasErrors(['senha']);

        $this->tela()->call('novo')->set('nome', 'X')->set('email', 'existe@exemplo.com')->call('salvar')->assertHasErrors(['formulario']);

        $this->tela()->call('novo')->set('nome', 'X')->set('email', 'z@exemplo.com')->set('perfilDoFormulario', 'superusuario')->call('salvar')->assertHasErrors(['perfilDoFormulario']);
    }

    public function test_edita_dados_sem_mudar_o_perfil_sem_pedir_senha(): void
    {
        $alvo = User::factory()->create(['name' => 'Antes']);

        $this->tela()->call('editar', $alvo->id)->set('nome', 'Depois')->call('salvar')->assertHasNoErrors()->assertSet('janela', null);

        $this->assertSame('Depois', $alvo->fresh()->name);
    }

    public function test_mudar_o_perfil_exige_a_senha_de_quem_opera(): void
    {
        $alvo = User::factory()->create();

        $tela = $this->tela()->call('editar', $alvo->id)->set('perfilDoFormulario', 'gestor');

        $tela->call('salvar')->assertHasErrors(['senhaAtual']);
        $this->assertSame(Perfil::Leitura, $alvo->fresh()->perfil);

        $tela->set('senhaAtual', 'senha-errada')->call('salvar')->assertHasErrors(['senhaAtual']);
        $this->assertSame(Perfil::Leitura, $alvo->fresh()->perfil);

        $tela->set('senhaAtual', self::SENHA)->call('salvar')->assertHasNoErrors();
        $this->assertSame(Perfil::Gestor, $alvo->fresh()->perfil);
    }

    public function test_acoes_sensiveis_pedem_a_senha_e_executam_com_ela(): void
    {
        $alvo = User::factory()->gestor()->create();

        $tela = $this->tela()->call('pedirConfirmacao', 'senha', $alvo->id)->assertSet('janela', 'confirmar');

        $tela->set('senhaAtual', 'errada')->call('confirmar')->assertHasErrors(['senhaAtual']);
        $this->assertFalse($alvo->fresh()->deve_alterar_senha);

        $tela->set('senhaAtual', self::SENHA)->call('confirmar')->assertHasNoErrors()->assertSet('janela', 'senha-gerada');

        $this->assertTrue($alvo->fresh()->deve_alterar_senha);
        $this->assertTrue(Hash::check($tela->get('senhaGerada'), $alvo->fresh()->password));
    }

    public function test_desativa_e_reativa_conta(): void
    {
        $alvo = User::factory()->gestor()->create();

        $this->tela()
            ->call('alternarSituacao', $alvo->id)
            ->assertSet('acao', 'desativar')
            ->set('senhaAtual', self::SENHA)
            ->call('confirmar');

        $this->assertFalse($alvo->fresh()->ativo);

        $this->tela()->call('alternarSituacao', $alvo->id);

        $this->assertTrue($alvo->fresh()->ativo);
    }

    public function test_exclui_conta_com_confirmacao_de_senha(): void
    {
        $alvo = User::factory()->gestor()->create();

        $this->tela()->call('pedirConfirmacao', 'excluir', $alvo->id)->set('senhaAtual', self::SENHA)->call('confirmar')->assertSet('janela', null);

        $this->assertFalse(User::whereKey($alvo->id)->exists());
    }

    public function test_redefinir_dois_fatores_pela_tela(): void
    {
        $alvo = User::factory()->admin()->comDoisFatores()->create();

        $this->tela()->call('pedirConfirmacao', 'dois-fatores', $alvo->id)->set('senhaAtual', self::SENHA)->call('confirmar');

        $this->assertNull($alvo->fresh()->two_factor_confirmed_at);
    }

    public function test_protecoes_do_servico_aparecem_como_mensagem_na_tela(): void
    {
        $this->tela()
            ->call('pedirConfirmacao', 'excluir', $this->admin->id)
            ->set('senhaAtual', self::SENHA)
            ->call('confirmar')
            ->assertHasErrors(['senhaAtual'])
            ->assertSee('Você não pode excluir a sua própria conta');

        $this->assertTrue(User::whereKey($this->admin->id)->exists());
    }

    public function test_tentativas_de_senha_sao_limitadas(): void
    {
        $alvo = User::factory()->create();
        $tela = $this->tela()->call('pedirConfirmacao', 'sessoes', $alvo->id);

        foreach (range(1, 5) as $_) {
            $tela->set('senhaAtual', 'errada')->call('confirmar');
        }

        $tela->set('senhaAtual', self::SENHA)->call('confirmar')->assertHasErrors(['senhaAtual'])->assertSee('Muitas tentativas');
    }

    public function test_acao_desconhecida_e_recusada_e_estado_nao_pode_ser_alterado_pelo_cliente(): void
    {
        $alvo = User::factory()->create();

        $this->tela()->call('pedirConfirmacao', 'apagar-tudo', $alvo->id)->assertStatus(422);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->tela()->set('usuarioId', $alvo->id);
    }

    public function test_detalhes_mostram_o_historico_da_conta(): void
    {
        $alvo = User::factory()->gestor()->create(['name' => 'Gabriela Neves']);
        AuditLog::factory()->create(['user_id' => $alvo->id, 'evento' => 'login']);
        AuditLog::factory()->create(['user_id' => $this->admin->id, 'evento' => 'usuario_desativado', 'dados' => ['alvo_id' => $alvo->id]]);

        $this->tela()->call('detalhes', $alvo->id)->assertSee(['Gabriela Neves', 'Entrou no sistema', 'Desativou um usuário', 'Atividade recente']);
    }

    public function test_so_administradores_usam_o_componente(): void
    {
        Livewire::actingAs(User::factory()->gestor()->create())->test(Usuarios::class)->assertForbidden();
    }
}
