<?php

namespace Tests\Feature;

use App\Enums\TipoDeVisual;
use App\Livewire\Painel;
use App\Models\PainelWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CriaCenarioDoPainel;
use Tests\TestCase;

class PainelTest extends TestCase
{
    use CriaCenarioDoPainel;
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->criarCenarioDoPainel();
        $this->usuario = User::factory()->gestor()->create();
    }

    private function painel(?User $usuario = null): Testable
    {
        return Livewire::actingAs($usuario ?? $this->usuario)->test(Painel::class);
    }

    /**
     * @return list<string> "tipo|indicador" na ordem do painel
     */
    private function visuaisDe(User $usuario): array
    {
        return $usuario->widgets()->get()->map(fn (PainelWidget $w): string => $w->tipo->value.'|'.$w->indicador)->all();
    }

    public function test_a_pagina_abre_para_usuarios_autenticados_e_exige_login(): void
    {
        $this->get(route('painel'))->assertRedirect(route('login'));

        $this->actingAs($this->usuario)->get(route('painel'))
            ->assertOk()
            ->assertSee('Meu painel')
            ->assertSee('Personalizar painel')
            ->assertSee('Valença');
    }

    public function test_primeiro_acesso_cria_o_painel_padrao_apenas_com_indicadores_ativos(): void
    {
        $this->painel()->assertSet('editando', false);

        $this->assertSame(
            ['destaque|cobertura_esf', 'destaque|populacao_total', 'evolucao|cobertura_esf', 'ranking|cobertura_esf'],
            $this->visuaisDe($this->usuario),
            'indicadores do padrão que não existem ou estão inativos são omitidos',
        );
        $this->assertSame([1, 1, 2, 2], $this->usuario->widgets()->pluck('largura')->all());
        $this->assertTrue($this->usuario->fresh()->painel_personalizado);
    }

    public function test_o_painel_padrao_nao_e_recriado_nem_duplicado(): void
    {
        $this->painel();
        $this->painel();

        $this->assertSame(4, $this->usuario->widgets()->count());

        $this->usuario->widgets()->delete();
        $this->painel()->assertSee('Seu painel está vazio');

        $this->assertSame(0, $this->usuario->widgets()->count(), 'quem removeu tudo de propósito não ganha o padrão de volta');
    }

    public function test_municipio_inicial_e_valenca_depois_o_ultimo_escolhido(): void
    {
        $this->painel()->assertSet('municipioId', 3306107);

        $this->usuario->update(['municipio_id' => $this->resende->id]);

        $this->painel()->assertSet('municipioId', $this->resende->id);
    }

    public function test_trocar_o_municipio_persiste_a_escolha_e_rejeita_municipio_inexistente(): void
    {
        $this->painel()
            ->set('municipioId', $this->voltaRedonda->id)
            ->assertSet('municipioId', $this->voltaRedonda->id);

        $this->assertSame($this->voltaRedonda->id, $this->usuario->fresh()->municipio_id);

        $this->painel()
            ->set('municipioId', 999999)
            ->assertSet('municipioId', $this->voltaRedonda->id);
    }

    public function test_municipio_inativo_nao_pode_ser_selecionado(): void
    {
        $this->caboFrio->update(['ativo' => false]);

        $this->painel()->set('municipioId', $this->caboFrio->id)->assertSet('municipioId', 3306107);
    }

    public function test_janela_de_meses_aceita_so_as_opcoes_validas(): void
    {
        $this->painel()->set('meses', 36)->assertSet('meses', 36);
        $this->assertSame(36, session('painel.meses'));

        $this->painel()->set('meses', 7)->assertSet('meses', 24);
    }

    public function test_adicionar_visual_vai_para_o_fim_com_a_largura_inicial_do_tipo(): void
    {
        $this->painel()->call('adicionar', 'destaque', 'icsap_taxa')->call('adicionar', 'ranking', 'icsap_taxa');

        $widgets = $this->usuario->widgets()->get();
        $ultimo = $widgets->last();

        $this->assertSame(6, $widgets->count());
        $this->assertSame(['ranking', 'icsap_taxa', 2], [$ultimo->tipo->value, $ultimo->indicador, $ultimo->largura]);
        $this->assertSame(1, $widgets[4]->largura);
        $this->assertSame(range(0, 5), $widgets->pluck('posicao')->all());
    }

    public function test_adicionar_ignora_duplicados_tipos_e_indicadores_invalidos(): void
    {
        $this->painel()
            ->call('adicionar', 'destaque', 'cobertura_esf')
            ->call('adicionar', 'pizza', 'cobertura_esf')
            ->call('adicionar', 'destaque', 'indicador_que_nao_existe');

        $this->assertCount(4, $this->visuaisDe($this->usuario));
    }

    public function test_indicador_desativado_nao_pode_ser_adicionado(): void
    {
        $this->icsap->update(['ativo' => false]);

        $this->painel()->call('adicionar', 'destaque', 'icsap_taxa');

        $this->assertNotContains('destaque|icsap_taxa', $this->visuaisDe($this->usuario));
    }

    public function test_remover_so_afeta_visuais_do_proprio_usuario(): void
    {
        $outro = User::factory()->gestor()->create();
        $this->painel($outro);
        $this->painel();

        $alheio = $outro->widgets()->first();
        $proprio = $this->usuario->widgets()->first();

        $this->painel()->call('remover', $alheio->id)->call('remover', $proprio->id);

        $this->assertNotNull($alheio->fresh(), 'visual de outro usuário não pode ser removido');
        $this->assertNull($proprio->fresh());
        $this->assertSame(3, $this->usuario->widgets()->count());
    }

    public function test_reordenar_grava_a_nova_ordem_ignorando_ids_alheios_e_anexando_os_esquecidos(): void
    {
        $outro = User::factory()->gestor()->create();
        $this->painel($outro);
        $this->painel();

        $ids = $this->usuario->widgets()->pluck('id')->all();
        $alheio = $outro->widgets()->first();
        $posicaoDoAlheio = $alheio->posicao;

        $this->painel()->call('reordenar', [$ids[3], $alheio->id, $ids[1], 'texto', $ids[3]]);

        $this->assertSame([$ids[3], $ids[1], $ids[0], $ids[2]], $this->usuario->widgets()->pluck('id')->all());
        $this->assertSame($posicaoDoAlheio, $alheio->fresh()->posicao);
    }

    public function test_mover_troca_de_lugar_e_respeita_os_limites(): void
    {
        $this->painel();
        $ids = $this->usuario->widgets()->pluck('id')->all();

        $this->painel()->call('mover', $ids[1], 'antes');
        $this->assertSame([$ids[1], $ids[0], $ids[2], $ids[3]], $this->usuario->widgets()->pluck('id')->all());

        $this->painel()->call('mover', $ids[1], 'antes');
        $this->assertSame([$ids[1], $ids[0], $ids[2], $ids[3]], $this->usuario->widgets()->pluck('id')->all(), 'o primeiro não sobe mais');

        $this->painel()->call('mover', $ids[3], 'depois');
        $this->assertSame([$ids[1], $ids[0], $ids[2], $ids[3]], $this->usuario->widgets()->pluck('id')->all(), 'o último não desce mais');

        $this->painel()->call('mover', $ids[0], 'depois')->call('mover', $ids[0], 'lateral');
        $this->assertSame([$ids[1], $ids[2], $ids[0], $ids[3]], $this->usuario->widgets()->pluck('id')->all());
    }

    public function test_alterar_largura_valida_o_intervalo_e_o_dono(): void
    {
        $outro = User::factory()->gestor()->create();
        $this->painel($outro);
        $this->painel();

        $proprio = $this->usuario->widgets()->first();
        $alheio = $outro->widgets()->first();

        $this->painel()->call('alterarLargura', $proprio->id, 3);
        $this->assertSame(3, $proprio->fresh()->largura);

        $this->painel()->call('alterarLargura', $proprio->id, 9)->call('alterarLargura', $proprio->id, 0);
        $this->assertSame(3, $proprio->fresh()->largura);

        $this->painel()->call('alterarLargura', $alheio->id, 3);
        $this->assertSame(1, $alheio->fresh()->largura);
    }

    public function test_restaurar_padrao_descarta_as_mudancas(): void
    {
        $this->painel()->call('adicionar', 'destaque', 'icsap_taxa')->call('remover', $this->usuario->widgets()->first()->id);

        $this->painel()->set('editando', true)->call('restaurarPadrao')->assertSet('editando', false);

        $this->assertSame(
            ['destaque|cobertura_esf', 'destaque|populacao_total', 'evolucao|cobertura_esf', 'ranking|cobertura_esf'],
            $this->visuaisDe($this->usuario),
        );
    }

    public function test_modo_de_edicao_alterna_e_fecha_a_galeria(): void
    {
        $this->painel()
            ->assertDontSee('Modo de edição')
            ->call('alternarEdicao')
            ->assertSet('editando', true)
            ->assertSee('Modo de edição')
            ->assertSee('Adicionar visual')
            ->call('abrirGaleria')
            ->assertSet('galeriaAberta', true)
            ->call('alternarEdicao')
            ->assertSet('galeriaAberta', false)
            ->assertSet('editando', false);
    }

    public function test_a_barra_de_edicao_de_cada_visual_so_aparece_no_modo_de_edicao(): void
    {
        $this->painel()
            ->assertSeeHtml('x-data="reordenavel"')
            ->assertDontSee('Arrastar para reordenar')
            ->assertDontSee('Mover para antes')
            ->assertDontSee('Remover')
            ->call('alternarEdicao')
            ->assertSeeHtml('data-arrastar')
            ->assertSee('Arrastar para reordenar')
            ->assertSee('Mover para antes')
            ->assertSee('Mover para depois')
            ->assertSee('Estreito')
            ->assertSee('Médio')
            ->assertSee('Largo')
            ->assertSee('Remover')
            ->call('alternarEdicao')
            ->assertDontSee('Arrastar para reordenar');
    }

    public function test_a_barra_de_edicao_marca_a_largura_atual(): void
    {
        $this->painel();
        $this->usuario->widgets()->update(['largura' => 1]);
        $this->usuario->widgets()->where('tipo', 'evolucao')->update(['largura' => 3]);

        $html = $this->painel()->call('alternarEdicao')->html();

        $this->assertSame(4, substr_count($html, 'aria-pressed="true"'), 'exatamente um botão de largura marcado por visual (são 4 visuais)');
    }

    public function test_galeria_lista_indicadores_por_dimensao_filtra_por_busca_e_marca_o_que_ja_esta_no_painel(): void
    {
        $this->painel()
            ->call('abrirGaleria')
            ->assertSee('Desempenho da APS')
            ->assertSee('Necessidade da APS')
            ->assertSee('Internações por condições sensíveis à APS')
            ->assertSee('✓ Destaque')
            ->set('busca', 'internações')
            ->assertSee('Internações por condições sensíveis à APS')
            ->assertDontSee('Cobertura da Estratégia Saúde da Família')
            ->set('busca', 'zzz')
            ->assertSee('Nenhum indicador encontrado');
    }

    public function test_galeria_so_oferece_indicadores_ativos(): void
    {
        $this->icsap->update(['ativo' => false]);

        $this->painel()->call('abrirGaleria')->assertDontSee('Internações por condições sensíveis à APS');
    }

    public function test_cada_usuario_tem_o_proprio_painel(): void
    {
        $outro = User::factory()->gestor()->create();

        $this->painel()->call('adicionar', 'destaque', 'icsap_taxa');
        $this->painel($outro);

        $this->assertCount(5, $this->visuaisDe($this->usuario));
        $this->assertCount(4, $this->visuaisDe($outro));
    }

    public function test_largura_define_as_colunas_ocupadas(): void
    {
        $this->painel();
        $this->usuario->widgets()->where('tipo', TipoDeVisual::Evolucao->value)->update(['largura' => 3]);

        $this->painel()->assertSeeHtml('md:col-span-2 lg:col-span-3');
    }
}
