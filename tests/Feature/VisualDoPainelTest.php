<?php

namespace Tests\Feature;

use App\Enums\TipoDeVisual;
use App\Livewire\Painel\Visual;
use App\Models\PainelWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Lazy;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\LivewireManager;
use Tests\Concerns\CriaCenarioDoPainel;
use Tests\TestCase;

class VisualDoPainelTest extends TestCase
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

    /**
     * O Livewire reinicia o estado de teste a cada componente: o carregamento sob demanda precisa ser
     * desligado antes de cada um para que o conteúdo (e não o esqueleto) seja renderizado.
     */
    private function livewire(User $usuario): LivewireManager
    {
        Livewire::withoutLazyLoading();

        return Livewire::actingAs($usuario);
    }

    private function visual(TipoDeVisual $tipo, string $codigo, ?int $municipio = null, ?User $dono = null): Testable
    {
        $dono ??= $this->usuario;
        $widget = PainelWidget::factory()->create(['user_id' => $dono->id, 'tipo' => $tipo, 'indicador' => $codigo]);

        return $this->livewire($dono)->test(Visual::class, [
            'widgetId' => $widget->id,
            'municipioId' => $municipio ?? $this->valenca->id,
            'meses' => 24,
        ]);
    }

    public function test_destaque_mostra_valor_variacao_comparacoes_analise_e_fonte(): void
    {
        $this->visual(TipoDeVisual::Destaque, 'cobertura_esf')
            ->assertSee('Cobertura da Estratégia Saúde da Família')
            ->assertSee('Dado de jul/2026')
            ->assertSee('88,0%')
            ->assertSee('+8,0 p.p.')
            ->assertSee('desde jul/2025')
            ->assertSee('melhora')
            ->assertSee('Entre os municípios da região')
            ->assertSee('1ª de 3')
            ->assertSee('75,0%')
            ->assertSee('Mediana do estado')
            ->assertSee('Análise do cenário atual')
            ->assertSee('Situação favorável')
            ->assertSee('Em Valença, o valor atual de “Cobertura da Estratégia Saúde da Família” é 88,0% (jul/2026).')
            ->assertSee('Fonte: e-Gestor APS (Ministério da Saúde)')
            ->assertSeeHtml('aria-label="Tendência dos últimos 7 valores');
    }

    public function test_o_botao_de_ajuda_explica_o_que_e_como_calcula_para_que_serve_e_como_interpretar(): void
    {
        $this->visual(TipoDeVisual::Destaque, 'cobertura_esf')
            ->assertSeeHtml('aria-label="Como ler este visual: Cobertura da Estratégia Saúde da Família"')
            ->assertSee('O que é')
            ->assertSee('Parcela da população coberta por equipes de Saúde da Família.')
            ->assertSee('Como é calculado')
            ->assertSee('Para que serve')
            ->assertSee('Como interpretar')
            ->assertSee('Quanto maior, melhor');
    }

    public function test_destaque_de_indicador_neutro_nao_mostra_selo_de_melhora_ou_piora(): void
    {
        $this->visual(TipoDeVisual::Destaque, 'populacao_total')
            ->assertSee('71.449')
            ->assertSee('pessoas')
            ->assertSee('Dado de 2025')
            ->assertSee('Informativo')
            ->assertDontSee('melhora')
            ->assertDontSee('piora');
    }

    public function test_evolucao_entrega_os_dados_ao_grafico_e_a_tabela_alternativa(): void
    {
        $this->visual(TipoDeVisual::Evolucao, 'cobertura_esf')
            ->assertSee('Evolução: Cobertura da Estratégia Saúde da Família')
            ->assertSeeHtml('x-data="grafico"')
            ->assertSeeHtml('data-dados=')
            ->assertSee('&quot;modo&quot;:&quot;evolucao&quot;', false)
            ->assertSee('Ver os valores em tabela')
            ->assertSee('Mediana do estado')
            ->assertSee('Entre jul/2025 e jul/2026');
    }

    public function test_ranking_mostra_posicao_e_tabela(): void
    {
        $this->visual(TipoDeVisual::Ranking, 'cobertura_esf', $this->resende->id)
            ->assertSee('Ranking: Cobertura da Estratégia Saúde da Família')
            ->assertSee('Merece atenção')
            ->assertSee('ocupa a 3ª posição')
            ->assertSee('Posição')
            ->assertSee('Volta Redonda');
    }

    public function test_destaque_mostra_a_faixa_de_posicao_com_menor_maior_e_mediana_e_o_sparkline_com_dica(): void
    {
        $this->visual(TipoDeVisual::Destaque, 'cobertura_esf')
            ->assertSee('menor')
            ->assertSee('maior')
            ->assertSee('mediana')
            ->assertSee('Quanto mais à direita, melhor.')
            ->assertSeeHtml('<title>jul/2026: 88,0%</title>')
            ->assertSee('passe o mouse sobre a linha');
    }

    public function test_destaque_de_indicador_neutro_nao_diz_para_que_lado_e_melhor(): void
    {
        $this->visual(TipoDeVisual::Destaque, 'populacao_total')
            ->assertDontSee('Quanto mais à direita, melhor.')
            ->assertDontSee('Quanto mais à esquerda, melhor.');
    }

    public function test_graficos_tem_barra_de_ferramentas_com_tabela_imagem_planilha_e_ampliar(): void
    {
        $this->visual(TipoDeVisual::Evolucao, 'cobertura_esf')
            ->assertSeeHtml('x-data="cartao"')
            ->assertSeeHtml('aria-label="Ver os valores em tabela"')
            ->assertSeeHtml('aria-label="Voltar ao gráfico"')
            ->assertSeeHtml('aria-label="Baixar o gráfico como imagem (PNG)"')
            ->assertSeeHtml('aria-label="Baixar os valores em planilha (CSV)"')
            ->assertSeeHtml('aria-label="Ampliar este visual"')
            ->assertSeeHtml('aria-label="Fechar a ampliação"')
            ->assertSeeHtml('data-tabela')
            ->assertSeeHtml('data-area-grafico')
            ->assertSee('Clique na legenda para esconder ou mostrar uma linha');
    }

    public function test_o_destaque_nao_tem_ferramentas_de_grafico(): void
    {
        $this->visual(TipoDeVisual::Destaque, 'cobertura_esf')
            ->assertDontSeeHtml('aria-label="Ampliar este visual"')
            ->assertDontSeeHtml('aria-label="Baixar o gráfico como imagem (PNG)"');
    }

    public function test_a_tabela_do_visual_traz_todos_os_valores_para_leitura_e_planilha(): void
    {
        $this->visual(TipoDeVisual::Evolucao, 'cobertura_esf')
            ->assertSeeHtml('<caption class="sr-only">Evolução: Cobertura da Estratégia Saúde da Família</caption>')
            ->assertSee('Período')
            ->assertSee('Mediana da região de saúde')
            ->assertSee('jan/2026');
    }

    public function test_ranking_tem_alternador_regiao_estado_dados_para_o_clique_e_legenda(): void
    {
        $this->visual(TipoDeVisual::Ranking, 'cobertura_esf', $this->resende->id)
            ->assertSee('Região de saúde')
            ->assertSee('Estado')
            ->assertSeeHtml('aria-pressed="true"')
            ->assertSeeHtml('data-clicavel="1"')
            ->assertSee('Município em foco')
            ->assertSee('Demais municípios')
            ->assertSee('Clique em um município para colocá-lo em foco em todo o painel');
    }

    public function test_trocar_o_ranking_para_o_estado_muda_os_dados_o_texto_e_a_mediana(): void
    {
        $pagina = $this->visual(TipoDeVisual::Ranking, 'cobertura_esf', $this->resende->id);

        $pagina->call('definirEscopo', 'estado')
            ->assertSet('escopo', 'estado')
            ->assertSee('No estado, Resende ocupa a 3ª posição entre 4 municípios')
            ->assertSee('Cabo Frio')
            ->assertSee('&quot;rotulo_da_mediana&quot;:&quot;Mediana do estado&quot;', false);

        $pagina->call('definirEscopo', 'regiao')
            ->assertSet('escopo', 'regiao')
            ->assertDontSee('No estado, Resende');
    }

    public function test_escopo_invalido_volta_para_a_regiao_e_nao_afeta_outros_tipos(): void
    {
        $this->visual(TipoDeVisual::Ranking, 'cobertura_esf', $this->resende->id)->call('definirEscopo', 'lua')->assertSet('escopo', 'regiao');

        $this->visual(TipoDeVisual::Destaque, 'cobertura_esf')->call('definirEscopo', 'estado')->assertDontSee('No estado, Valença ocupa');
    }

    public function test_mapa_mostra_legenda_grafico_clicavel_e_tabela(): void
    {
        $this->visual(TipoDeVisual::Mapa, 'cobertura_esf')
            ->assertSee('Mapa: Cobertura da Estratégia Saúde da Família')
            ->assertSee('Legenda do mapa')
            ->assertSee('Menor')
            ->assertSee('Maior')
            ->assertSeeHtml('data-clicavel="1"')
            ->assertSee('&quot;modo&quot;:&quot;mapa&quot;', false)
            ->assertSee('Valor')
            ->assertSee('Clique em um município para colocá-lo em foco');
    }

    public function test_um_tipo_que_nao_se_aplica_ao_indicador_vira_aviso(): void
    {
        $this->visual(TipoDeVisual::Matriz, 'cobertura_esf')->assertSee('Este visual não está mais disponível');
    }

    public function test_sem_dados_mostra_estado_vazio_e_explica_a_fonte(): void
    {
        $this->visual(TipoDeVisual::Destaque, 'icsap_taxa', $this->caboFrio->id)
            ->assertSee('Sem dados para exibir.')
            ->assertSee('Sem dados')
            ->assertSee('Ainda não há dados de “Internações por condições sensíveis à APS” para Cabo Frio.')
            ->assertSee('SIH/SUS');
    }

    public function test_o_visual_de_outro_usuario_nunca_e_exibido(): void
    {
        $outro = User::factory()->gestor()->create();
        $widget = PainelWidget::factory()->create(['user_id' => $outro->id, 'tipo' => TipoDeVisual::Destaque, 'indicador' => 'cobertura_esf']);

        $this->livewire($this->usuario)->test(Visual::class, ['widgetId' => $widget->id, 'municipioId' => $this->valenca->id, 'meses' => 24])
            ->assertSee('Este visual não está mais disponível')
            ->assertDontSee('88,0%');
    }

    public function test_indicador_removido_vira_aviso_em_vez_de_quebrar_o_painel(): void
    {
        $this->visual(TipoDeVisual::Destaque, 'icsap_taxa');
        $this->icsap->delete();

        $widget = PainelWidget::where('indicador', 'icsap_taxa')->firstOrFail();

        $this->livewire($this->usuario)->test(Visual::class, ['widgetId' => $widget->id, 'municipioId' => $this->valenca->id, 'meses' => 24])
            ->assertSee('Este visual não está mais disponível')
            ->assertSee('No modo de edição você pode retirá-lo do painel');
    }

    public function test_o_identificador_do_visual_nao_pode_ser_adulterado(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->visual(TipoDeVisual::Destaque, 'cobertura_esf')->set('widgetId', 1);
    }

    public function test_nomes_vindos_dos_dados_sao_escapados_na_tela_e_nos_atributos(): void
    {
        $this->resende->update(['nome' => '<img src=x onerror=alert(1)>']);

        $ranking = $this->visual(TipoDeVisual::Ranking, 'cobertura_esf')->html();

        $this->assertStringNotContainsString('<img src=x', $ranking);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $ranking);

        $this->resende->update(['nome' => '"><script>alert(1)</script>']);

        $evolucao = $this->visual(TipoDeVisual::Evolucao, 'cobertura_esf', $this->resende->id)->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $evolucao);
    }

    public function test_carregamento_preguicoso_mostra_esqueleto_acessivel(): void
    {
        $esqueleto = view('livewire.painel.visual-carregando')->render();

        $this->assertStringContainsString('role="status"', $esqueleto);
        $this->assertStringContainsString('aria-label="Carregando visual"', $esqueleto);

        $this->assertSame(
            'App\Livewire\Painel\Visual',
            Visual::class,
        );
        $this->assertNotEmpty((new \ReflectionClass(Visual::class))->getAttributes(Lazy::class), 'o visual deve carregar sob demanda');
    }
}
