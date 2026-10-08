<?php

namespace Tests\Feature;

use App\Livewire\Conta\Seguranca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class DoisFatoresTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_sem_dois_fatores_so_acessa_a_pagina_de_seguranca(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('painel'))->assertRedirect(route('conta.seguranca'));
        $this->actingAs($admin)->get(route('conta.seguranca'))->assertOk();
    }

    public function test_administrador_com_dois_fatores_acessa_o_painel(): void
    {
        $admin = User::factory()->admin()->comDoisFatores()->create();

        $this->actingAs($admin)->get(route('painel'))->assertOk();
    }

    public function test_gestor_nao_e_obrigado_a_ativar_dois_fatores(): void
    {
        $gestor = User::factory()->gestor()->create();

        $this->actingAs($gestor)->get(route('painel'))->assertOk();
    }

    public function test_usuario_inicia_a_ativacao_e_o_segredo_fica_criptografado(): void
    {
        $usuario = User::factory()->create();

        Livewire::actingAs($usuario)->test(Seguranca::class)
            ->call('iniciarDoisFatores')
            ->assertSee('Confirmar e ativar');

        $bruto = $usuario->fresh()->getRawOriginal('two_factor_secret');

        $this->assertNotNull($bruto);
        $this->assertNull($usuario->fresh()->two_factor_confirmed_at);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $usuario->id, 'evento' => 'dois_fatores_iniciado']);
        $this->assertDatabaseMissing('users', ['id' => $usuario->id, 'two_factor_secret' => decrypt($bruto)]);
    }

    public function test_ao_confirmar_o_codigo_correto_ativa_e_exibe_os_codigos_de_recuperacao(): void
    {
        $usuario = User::factory()->create();

        $componente = Livewire::actingAs($usuario)->test(Seguranca::class)->call('iniciarDoisFatores');

        $codigoValido = app(Google2FA::class)->getCurrentOtp(decrypt($usuario->fresh()->two_factor_secret));

        $componente->set('codigoConfirmacao', $codigoValido)
            ->call('confirmarDoisFatores')
            ->assertHasNoErrors()
            ->assertSee('Guarde estes códigos de recuperação')
            ->assertSee($usuario->fresh()->recoveryCodes()[0]);

        $this->assertNotNull($usuario->fresh()->two_factor_confirmed_at);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $usuario->id, 'evento' => 'dois_fatores_ativado']);
    }

    public function test_codigo_de_confirmacao_invalido_nao_ativa(): void
    {
        $usuario = User::factory()->create();

        Livewire::actingAs($usuario)->test(Seguranca::class)
            ->call('iniciarDoisFatores')
            ->set('codigoConfirmacao', '000000')
            ->call('confirmarDoisFatores')
            ->assertHasErrors('codigoConfirmacao');

        $this->assertNull($usuario->fresh()->two_factor_confirmed_at);
    }

    public function test_administrador_nao_consegue_desativar_dois_fatores(): void
    {
        $admin = User::factory()->admin()->comDoisFatores()->create(['password' => 'Senha-Forte-123!']);

        Livewire::actingAs($admin)->test(Seguranca::class)
            ->set('senhaParaAlterarDoisFatores', 'Senha-Forte-123!')
            ->call('desativarDoisFatores')
            ->assertHasErrors('senhaParaAlterarDoisFatores');

        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at);
    }

    public function test_desativar_dois_fatores_exige_a_senha_atual(): void
    {
        $usuario = User::factory()->comDoisFatores()->create(['password' => 'Senha-Forte-123!']);

        Livewire::actingAs($usuario)->test(Seguranca::class)
            ->set('senhaParaAlterarDoisFatores', 'senha-errada')
            ->call('desativarDoisFatores')
            ->assertHasErrors('senhaParaAlterarDoisFatores');

        $this->assertNotNull($usuario->fresh()->two_factor_confirmed_at);

        Livewire::actingAs($usuario)->test(Seguranca::class)
            ->set('senhaParaAlterarDoisFatores', 'Senha-Forte-123!')
            ->call('desativarDoisFatores')
            ->assertHasNoErrors();

        $this->assertNull($usuario->fresh()->two_factor_secret);
    }

    public function test_senha_nova_precisa_atender_a_politica(): void
    {
        $usuario = User::factory()->create(['password' => 'Senha-Forte-123!']);

        Livewire::actingAs($usuario)->test(Seguranca::class)
            ->set('current_password', 'Senha-Forte-123!')
            ->set('password', 'curta')
            ->set('password_confirmation', 'curta')
            ->call('alterarSenha')
            ->assertHasErrors('password');

        Livewire::actingAs($usuario)->test(Seguranca::class)
            ->set('current_password', 'Senha-Forte-123!')
            ->set('password', 'Outra-Senha-Forte-456!')
            ->set('password_confirmation', 'Outra-Senha-Forte-456!')
            ->call('alterarSenha')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('audit_logs', ['user_id' => $usuario->id, 'evento' => 'senha_alterada']);
    }
}
