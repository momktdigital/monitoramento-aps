<?php

namespace Tests\Feature;

use App\Enums\Dimensao;
use App\Enums\Periodicidade;
use App\Enums\Polaridade;
use App\Livewire\Painel;
use App\Models\Indicador;
use App\Models\PainelArea;
use App\Models\PainelWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CriaCenarioDoPainel;
use Tests\TestCase;

/**
 * No cenário de teste existem três indicadores ativos (população, cobertura da ESF e ICSAP). O painel padrão tem uma
 * área para cada assunto deles, na ordem de `config/painel.php`: População, Cobertura e estrutura, Internações e mortalidade.
 */
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

    /**
     * @param  array<string, mixed>  $parametros  parâmetros do endereço (ex.: area)
     */
    private function painel(?User $usuario = null, array $parametros = []): Testable
    {
        return Livewire::actingAs($usuario ?? $this->usuario)->withQueryParams($parametros)->test(Painel::class);
    }

    /**
     * @return list<string> nomes das áreas na ordem das abas
     */
    private function nomesDasAreas(?User $usuario = null): array
    {
        return ($usuario ?? $this->usuario)->areas()->pluck('nome')->all();
    }

    private function area(string $nome, ?User $usuario = null): PainelArea
    {
        $usuario ??= $this->usuario;

        // O painel é criado na primeira visita: garante que ela já aconteceu.
        if (! $usuario->areas()->exists()) {
            $this->painel($usuario);
        }

        return $usuario->areas()->where('nome', $nome)->firstOrFail();
    }

    /**
     * @return list<string> "tipo|indicador" na ordem da área
     */
    private function visuaisDa(PainelArea $area): array
    {
        return $area->widgets()->get()->map(fn (PainelWidget $w): string => $w->tipo->value.'|'.$w->indicador)->all();
    }

    // ------------------------------------------------------------------ Página e painel padrão

    public function test_a_pagina_abre_para_usuarios_autenticados_e_exige_login(): void
    {
        $this->get(route('painel'))->assertRedirect(route('login'));

        $this->actingAs($this->usuario)->get(route('painel'))
            ->assertOk()
            ->assertSee('Meu painel')
            ->assertSee('Personalizar painel')
            ->assertSee('Valença');
    }

    public function test_primeiro_acesso_cria_uma_area_por_assunto_com_todos_os_visuais_dos_indicadores_ativos(): void
    {
        $this->painel()->assertSet('editando', false);

        $this->assertSame(['População', 'Cobertura e estrutura', 'Internações e mortalidade'], $this->nomesDasAreas());
        $this->assertSame(
            ['destaque|cobertura_esf', 'evolucao|cobertura_esf', 'ranking|cobertura_esf', 'mapa|cobertura_esf'],
            $this->visuaisDa($this->area('Cobertura e estrutura')),
            'cada indicador traz destaque, evolução, ranking e mapa',
        );
        $this->assertSame([1, 2, 1, 2], $this->area('Cobertura e estrutura')->widgets()->pluck('largura')->all(), 'destaque + evolução e ranking + mapa completam uma linha');
        $this->assertSame(['População' => 'populacao', 'Cobertura e estrutura' => 'cobertura', 'Internações e mortalidade' => 'internacoes'], $this->usuario->areas()->pluck('modelo', 'nome')->all());
        $this->assertTrue($this->usuario->fresh()->painel_personalizado);
    }

    public function test_a_primeira_area_e_a_inicial_e_so_uma_area_e_inicial(): void
    {
        $this->painel();

        $this->assertSame(['População'], $this->usuario->areas()->where('padrao', true)->pluck('nome')->all());
    }

    public function test_indicador_ativo_que_nao_esta_em_nenhuma_area_vai_para_outros_indicadores(): void
    {
        Indicador::factory()->create(['codigo' => 'indicador_novo', 'nome' => 'Indicador novo', 'dimensao' => Dimensao::Desempenho, 'polaridade' => Polaridade::MaiorMelhor, 'periodicidade' => Periodicidade::Mensal]);

        $this->painel();

        $this->assertSame('Outros indicadores', last($this->nomesDasAreas()));
        $this->assertSame(['destaque|indicador_novo', 'evolucao|indicador_novo', 'ranking|indicador_novo', 'mapa|indicador_novo'], $this->visuaisDa($this->area('Outros indicadores')));
    }

    public function test_indicadores_inativos_ou_ocultos_ficam_de_fora_e_areas_vazias_nao_sao_criadas(): void
    {
        $this->icsap->update(['ativo' => false]);
        $this->populacao->update(['visivel' => false]);

        $this->painel();

        $this->assertSame(['Cobertura e estrutura'], $this->nomesDasAreas());
    }

    public function test_sem_nenhum_indicador_ativo_o_painel_nasce_com_uma_area_vazia(): void
    {
        Indicador::query()->update(['ativo' => false]);

        $this->painel()->assertSee('está vazia');

        $this->assertSame(['Meu painel'], $this->nomesDasAreas());
        $this->assertTrue($this->area('Meu painel')->padrao);
    }

    public function test_o_painel_padrao_nao_e_recriado_nem_duplicado(): void
    {
        $this->painel();
        $this->painel();

        $this->assertCount(3, $this->nomesDasAreas());
        $this->assertSame(12, $this->usuario->widgets()->count());
    }

    public function test_cada_usuario_tem_o_proprio_painel(): void
    {
        $outro = User::factory()->gestor()->create();

        $this->painel()->call('alternarEdicao')->call('excluirArea', $this->area('População')->id);
        $this->painel($outro);

        $this->assertCount(2, $this->nomesDasAreas());
        $this->assertCount(3, $this->nomesDasAreas($outro));
    }

    // ------------------------------------------------------------------ Município e período

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

    public function test_clique_em_um_municipio_de_um_grafico_o_coloca_em_foco(): void
    {
        $this->painel()
            ->dispatch('municipio-escolhido', id: $this->resende->id)
            ->assertSet('municipioId', $this->resende->id);

        $this->assertSame($this->resende->id, $this->usuario->fresh()->municipio_id);
    }

    public function test_janela_de_meses_aceita_so_as_opcoes_validas(): void
    {
        $this->painel()->set('meses', 36)->assertSet('meses', 36);
        $this->assertSame(36, session('painel.meses'));

        $this->painel()->set('meses', 7)->assertSet('meses', 24);
    }

    // ------------------------------------------------------------------ Abas

    public function test_abre_na_area_inicial_e_as_abas_mostram_nome_estrela_e_quantidade(): void
    {
        $this->painel()
            ->assertSet('areaId', $this->area('População')->id)
            ->assertSee('Cobertura e estrutura')
            ->assertSee('Área inicial:')
            ->assertSeeHtml('role="tablist"')
            ->assertSeeHtml('aria-selected="true"');
    }

    public function test_abrir_outra_area_troca_os_visuais_e_o_endereco_leva_direto_a_ela(): void
    {
        $cobertura = $this->area('Cobertura e estrutura');

        $this->painel()->call('abrirArea', $cobertura->id)->assertSet('areaId', $cobertura->id);

        $this->painel(parametros: ['area' => $cobertura->id])->assertSet('areaId', $cobertura->id);
    }

    public function test_area_inexistente_ou_de_outro_usuario_cai_na_area_inicial(): void
    {
        $outro = User::factory()->gestor()->create();
        $this->painel($outro);
        $alheia = $outro->areas()->first();

        $this->painel(parametros: ['area' => $alheia->id])->assertSet('areaId', $this->area('População')->id);
        $this->painel()->call('abrirArea', $alheia->id)->assertSet('areaId', $this->area('População')->id);
        $this->painel(parametros: ['area' => 99999])->assertSet('areaId', $this->area('População')->id);
    }

    public function test_setas_do_teclado_percorrem_as_abas_e_voltam_ao_inicio(): void
    {
        $pagina = $this->painel();
        $areas = $this->usuario->areas()->pluck('id')->all();

        $pagina->assertSet('areaId', $areas[0]);

        $pagina->call('navegarArea', 'proxima')->assertSet('areaId', $areas[1]);
        $pagina->call('navegarArea', 'proxima')->assertSet('areaId', $areas[2]);
        $pagina->call('navegarArea', 'proxima')->assertSet('areaId', $areas[0]);
        $pagina->call('navegarArea', 'anterior')->assertSet('areaId', $areas[2]);
    }

    // ------------------------------------------------------------------ Gerenciar áreas

    public function test_criar_area_vazia_abre_nela_e_vai_para_o_fim_das_abas(): void
    {
        $pagina = $this->painel()
            ->call('alternarEdicao')
            ->call('novaArea')
            ->assertSet('formularioDeAreaAberto', true)
            ->set('areaNome', '  Reunião   de segunda ')
            ->call('salvarArea');

        $nova = $this->area('Reunião de segunda');

        $pagina->assertSet('formularioDeAreaAberto', false)->assertSet('areaId', $nova->id);

        $this->assertSame('Reunião de segunda', last($this->nomesDasAreas()), 'espaços extras são arrumados');
        $this->assertFalse($nova->padrao);
        $this->assertNull($nova->modelo);
        $this->assertSame(0, $nova->widgets()->count());
    }

    public function test_criar_area_a_partir_de_um_modelo_ja_traz_os_visuais_e_guarda_o_modelo(): void
    {
        $this->painel()->call('novaArea')->set('areaNome', 'Minha cobertura')->set('areaModelo', 'cobertura')->call('salvarArea');

        $nova = $this->area('Minha cobertura');

        $this->assertSame('cobertura', $nova->modelo);
        $this->assertCount(4, $nova->widgets);
    }

    public function test_nome_da_area_e_obrigatorio_limitado_e_unico_sem_diferenciar_maiusculas(): void
    {
        $this->painel()->call('novaArea')->set('areaNome', '   ')->call('salvarArea')->assertHasErrors(['areaNome' => 'required']);
        $this->painel()->call('novaArea')->set('areaNome', str_repeat('a', 41))->call('salvarArea')->assertHasErrors(['areaNome' => 'max']);
        $this->painel()->call('novaArea')->set('areaNome', 'população')->call('salvarArea')->assertHasErrors('areaNome');

        $this->assertCount(3, $this->nomesDasAreas());
    }

    public function test_nao_cria_alem_do_limite_de_areas(): void
    {
        config(['painel.maximo_de_areas' => 3]);

        $this->painel()->call('alternarEdicao')->assertSee('Limite de 3 áreas')->call('novaArea')->assertSet('formularioDeAreaAberto', false);

        $this->painel()->set('areaNome', 'Quarta')->call('salvarArea');
        $this->assertCount(3, $this->nomesDasAreas());
    }

    public function test_renomear_area_mantem_o_conteudo_e_permite_manter_o_proprio_nome(): void
    {
        $cobertura = $this->area('Cobertura e estrutura');

        $this->painel()->call('renomearArea', $cobertura->id)->assertSet('areaNome', 'Cobertura e estrutura')->set('areaNome', 'Equipes')->call('salvarArea')->assertHasNoErrors();

        $this->assertSame('Equipes', $cobertura->fresh()->nome);
        $this->assertCount(4, $cobertura->fresh()->widgets);

        $this->painel()->call('renomearArea', $cobertura->id)->set('areaNome', 'equipes')->call('salvarArea')->assertHasNoErrors();
        $this->painel()->call('renomearArea', $cobertura->id)->set('areaNome', 'População')->call('salvarArea')->assertHasErrors('areaNome');
    }

    public function test_cancelar_fecha_o_formulario_sem_criar_nada(): void
    {
        $this->painel()->call('novaArea')->set('areaNome', 'Rascunho')->call('cancelarArea')->assertSet('formularioDeAreaAberto', false);

        $this->assertCount(3, $this->nomesDasAreas());
    }

    public function test_excluir_area_apaga_os_visuais_dela_e_leva_para_a_inicial(): void
    {
        $cobertura = $this->area('Cobertura e estrutura');
        $this->painel();

        $this->painel()->call('abrirArea', $cobertura->id)->call('excluirArea', $cobertura->id)->assertSet('areaId', $this->area('População')->id);

        $this->assertSame(['População', 'Internações e mortalidade'], $this->nomesDasAreas());
        $this->assertSame(0, PainelWidget::where('area_id', $cobertura->id)->count());
        $this->assertSame(8, $this->usuario->widgets()->count());
    }

    public function test_excluir_a_area_inicial_passa_o_posto_para_a_primeira_que_sobrar(): void
    {
        $this->painel()->call('excluirArea', $this->area('População')->id);

        $this->assertSame('Cobertura e estrutura', $this->usuario->areas()->where('padrao', true)->value('nome'));
        $this->assertSame(1, $this->usuario->areas()->where('padrao', true)->count());
    }

    public function test_a_ultima_area_nao_pode_ser_excluida(): void
    {
        $this->icsap->update(['ativo' => false]);
        $this->populacao->update(['ativo' => false]);
        $this->painel();

        $unica = $this->area('Cobertura e estrutura');

        $this->painel()->call('excluirArea', $unica->id);

        $this->assertNotNull($unica->fresh());
    }

    public function test_nao_exclui_area_de_outro_usuario(): void
    {
        $outro = User::factory()->gestor()->create();
        $this->painel($outro);
        $alheia = $outro->areas()->first();

        $this->painel()->call('excluirArea', $alheia->id);

        $this->assertNotNull($alheia->fresh());
    }

    public function test_definir_area_inicial_troca_a_estrela_e_ela_abre_primeiro_na_proxima_vez(): void
    {
        $internacoes = $this->area('Internações e mortalidade');
        $this->painel();

        $this->painel()->call('definirComoInicial', $internacoes->id);

        $this->assertSame(['Internações e mortalidade'], $this->usuario->areas()->where('padrao', true)->pluck('nome')->all());
        $this->painel()->assertSet('areaId', $internacoes->id);
    }

    public function test_definir_inicial_ignora_area_de_outro_usuario(): void
    {
        $outro = User::factory()->gestor()->create();
        $this->painel($outro);
        $this->painel();

        $this->painel()->call('definirComoInicial', $outro->areas()->skip(1)->first()->id);

        $this->assertSame('População', $this->usuario->areas()->where('padrao', true)->value('nome'));
        $this->assertSame('População', $outro->areas()->where('padrao', true)->value('nome'));
    }

    public function test_mover_area_troca_a_posicao_das_abas_e_respeita_os_limites(): void
    {
        $this->painel();
        $ids = $this->usuario->areas()->pluck('id')->all();

        $this->painel()->call('moverArea', $ids[1], 'antes');
        $this->assertSame([$ids[1], $ids[0], $ids[2]], $this->usuario->areas()->pluck('id')->all());

        $this->painel()->call('moverArea', $ids[1], 'antes');
        $this->assertSame([$ids[1], $ids[0], $ids[2]], $this->usuario->areas()->pluck('id')->all(), 'a primeira não sobe mais');

        $this->painel()->call('moverArea', $ids[2], 'depois');
        $this->assertSame([$ids[1], $ids[0], $ids[2]], $this->usuario->areas()->pluck('id')->all(), 'a última não desce mais');

        $this->painel()->call('moverArea', $ids[0], 'depois');
        $this->assertSame([$ids[1], $ids[2], $ids[0]], $this->usuario->areas()->pluck('id')->all());
    }

    public function test_restaurar_area_devolve_o_conteudo_original_sem_mudar_nome_nem_lugar(): void
    {
        $cobertura = $this->area('Cobertura e estrutura');
        $this->painel();

        $this->painel()->call('abrirArea', $cobertura->id)->call('alternarVisual', 'ranking', 'cobertura_esf')->call('remover', $cobertura->widgets()->first()->id)->call('renomearArea', $cobertura->id)->set('areaNome', 'Meu nome')->call('salvarArea');
        $this->assertCount(2, $cobertura->fresh()->widgets);

        $this->painel()->call('restaurarArea', $cobertura->id);

        $this->assertCount(4, $cobertura->fresh()->widgets);
        $this->assertSame('Meu nome', $cobertura->fresh()->nome);
    }

    public function test_area_criada_do_zero_nao_tem_o_que_restaurar(): void
    {
        $this->painel()->call('novaArea')->set('areaNome', 'Do zero')->call('salvarArea');
        $propria = $this->area('Do zero');
        $this->painel()->call('abrirArea', $propria->id)->call('alternarVisual', 'destaque', 'cobertura_esf');

        $this->painel()->call('restaurarArea', $propria->id);

        $this->assertCount(1, $propria->fresh()->widgets, 'não há modelo: nada muda');
        $this->painel()->call('alternarEdicao')->call('abrirArea', $propria->id)->assertDontSee('Restaurar esta área');
    }

    public function test_restaurar_o_painel_inteiro_substitui_areas_e_visuais_e_volta_para_a_inicial(): void
    {
        $this->painel();

        $pagina = $this->painel()
            ->call('alternarEdicao')
            ->call('novaArea')->set('areaNome', 'Extra')->call('salvarArea')
            ->call('excluirArea', $this->area('População')->id);

        $this->assertContains('Extra', $this->nomesDasAreas());

        $pagina->call('restaurarPadrao')->assertSet('editando', false)->assertSet('areaId', $this->area('População')->id);

        $this->assertSame(['População', 'Cobertura e estrutura', 'Internações e mortalidade'], $this->nomesDasAreas());
        $this->assertSame(12, $this->usuario->widgets()->count());
    }

    // ------------------------------------------------------------------ Visuais da área

    public function test_adicionar_pela_galeria_vai_para_o_fim_da_area_aberta_com_a_largura_do_tipo(): void
    {
        $this->painel()->call('novaArea')->set('areaNome', 'Minha')->call('salvarArea')
            ->call('alternarVisual', 'destaque', 'icsap_taxa')
            ->call('alternarVisual', 'ranking', 'icsap_taxa')
            ->call('alternarVisual', 'mapa', 'icsap_taxa');

        $widgets = $this->area('Minha')->widgets()->get();

        $this->assertSame(['destaque|icsap_taxa', 'ranking|icsap_taxa', 'mapa|icsap_taxa'], $widgets->map(fn ($w) => $w->tipo->value.'|'.$w->indicador)->all());
        $this->assertSame([1, 1, 2], $widgets->pluck('largura')->all());
        $this->assertSame([0, 1, 2], $widgets->pluck('posicao')->all());
    }

    public function test_clicar_de_novo_em_um_visual_da_galeria_o_remove_da_area(): void
    {
        $cobertura = $this->area('Cobertura e estrutura');
        $this->painel();

        $this->painel()->call('abrirArea', $cobertura->id)
            ->call('alternarVisual', 'ranking', 'cobertura_esf')
            ->call('alternarVisual', 'mapa', 'cobertura_esf');

        $this->assertSame(['destaque|cobertura_esf', 'evolucao|cobertura_esf'], $this->visuaisDa($cobertura));
    }

    public function test_o_mesmo_visual_pode_estar_em_areas_diferentes_mas_nao_repetido_na_mesma(): void
    {
        $this->painel()->call('novaArea')->set('areaNome', 'Repete')->call('salvarArea')->call('alternarVisual', 'destaque', 'cobertura_esf');

        $this->assertContains('destaque|cobertura_esf', $this->visuaisDa($this->area('Repete')));
        $this->assertContains('destaque|cobertura_esf', $this->visuaisDa($this->area('Cobertura e estrutura')));
        $this->assertSame(1, PainelWidget::where('area_id', $this->area('Repete')->id)->count());
    }

    public function test_galeria_ignora_tipos_e_indicadores_invalidos_e_a_matriz_so_existe_para_a_prioridade(): void
    {
        $this->painel();
        $total = $this->usuario->widgets()->count();

        $this->painel()
            ->call('alternarVisual', 'pizza', 'cobertura_esf')
            ->call('alternarVisual', 'destaque', 'indicador_que_nao_existe')
            ->call('alternarVisual', 'matriz', 'cobertura_esf');

        $this->assertSame($total, $this->usuario->widgets()->count());
    }

    public function test_indicador_desativado_nao_pode_ser_adicionado(): void
    {
        $this->painel();
        $this->icsap->update(['ativo' => false]);

        $total = $this->usuario->widgets()->count();
        $this->painel()->call('alternarVisual', 'destaque', 'icsap_taxa');

        $this->assertSame($total, $this->usuario->widgets()->count(), 'indicador inativo não entra: nem some o que já estava');
    }

    public function test_adicionar_ou_remover_todos_os_visuais_de_um_indicador(): void
    {
        $this->painel()->call('novaArea')->set('areaNome', 'Tudo')->call('salvarArea')->call('alternarIndicador', 'icsap_taxa');

        $this->assertCount(4, $this->area('Tudo')->widgets, 'destaque, evolução, ranking e mapa');

        $this->painel()->call('abrirArea', $this->area('Tudo')->id)->call('alternarVisual', 'mapa', 'icsap_taxa')->call('alternarIndicador', 'icsap_taxa');
        $this->assertCount(4, $this->area('Tudo')->widgets, 'faltando um, "adicionar todos" completa');

        $this->painel()->call('abrirArea', $this->area('Tudo')->id)->call('alternarIndicador', 'icsap_taxa');
        $this->assertCount(0, $this->area('Tudo')->widgets, 'com todos presentes, o mesmo botão remove todos');
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
        $this->assertSame(11, $this->usuario->widgets()->count());
    }

    public function test_reordenar_vale_so_para_a_area_aberta_ignorando_ids_alheios_e_anexando_os_esquecidos(): void
    {
        $outro = User::factory()->gestor()->create();
        $this->painel($outro);
        $this->painel();

        $area = $this->area('População');
        $ids = $area->widgets()->pluck('id')->all();
        $deOutraArea = $this->area('Cobertura e estrutura')->widgets()->first();
        $alheio = $outro->widgets()->first();
        $posicaoDoAlheio = $alheio->posicao;
        $posicaoDeOutraArea = $deOutraArea->posicao;

        $this->painel()->call('reordenar', [$ids[3], $alheio->id, $deOutraArea->id, $ids[1], 'texto', $ids[3]]);

        $this->assertSame([$ids[3], $ids[1], $ids[0], $ids[2]], $area->widgets()->pluck('id')->all());
        $this->assertSame($posicaoDoAlheio, $alheio->fresh()->posicao);
        $this->assertSame($posicaoDeOutraArea, $deOutraArea->fresh()->posicao);
    }

    public function test_mover_troca_de_lugar_dentro_da_area_e_respeita_os_limites(): void
    {
        $this->painel();
        $area = $this->area('População');
        $ids = $area->widgets()->pluck('id')->all();

        $this->painel()->call('mover', $ids[1], 'antes');
        $this->assertSame([$ids[1], $ids[0], $ids[2], $ids[3]], $area->widgets()->pluck('id')->all());

        $this->painel()->call('mover', $ids[1], 'antes');
        $this->assertSame([$ids[1], $ids[0], $ids[2], $ids[3]], $area->widgets()->pluck('id')->all(), 'o primeiro não sobe mais');

        $this->painel()->call('mover', $ids[3], 'depois');
        $this->assertSame([$ids[1], $ids[0], $ids[2], $ids[3]], $area->widgets()->pluck('id')->all(), 'o último não desce mais');

        $this->painel()->call('mover', $ids[0], 'depois')->call('mover', $ids[0], 'lateral');
        $this->assertSame([$ids[1], $ids[2], $ids[0], $ids[3]], $area->widgets()->pluck('id')->all());
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

    // ------------------------------------------------------------------ Interface

    public function test_modo_de_edicao_mostra_o_gerenciador_da_area_e_fecha_a_galeria_e_o_formulario(): void
    {
        $this->painel()
            ->assertDontSee('Editando a área')
            ->assertDontSee('Nova área')
            ->call('alternarEdicao')
            ->assertSet('editando', true)
            ->assertSee('Editando a área “População”')
            ->assertSee('Adicionar visuais')
            ->assertSee('Nova área')
            ->assertSee('Restaurar painel padrão')
            ->call('abrirGaleria')
            ->assertSet('galeriaAberta', true)
            ->call('novaArea')
            ->assertSet('galeriaAberta', false)
            ->assertSet('formularioDeAreaAberto', true)
            ->call('alternarEdicao')
            ->assertSet('formularioDeAreaAberto', false)
            ->assertSet('editando', false);
    }

    public function test_o_gerenciador_da_area_mostra_so_o_que_se_aplica(): void
    {
        $this->painel()->call('alternarEdicao')
            ->assertSee('Esta é a área inicial')
            ->assertDontSee('Definir como inicial')
            ->assertSee('Restaurar esta área')
            ->call('abrirArea', $this->area('Cobertura e estrutura')->id)
            ->assertSee('Definir como inicial')
            ->assertDontSee('Esta é a área inicial');
    }

    public function test_a_barra_de_edicao_de_cada_visual_so_aparece_no_modo_de_edicao(): void
    {
        $this->painel()
            ->assertSeeHtml('x-data="reordenavel"')
            ->assertDontSee('Arrastar para reordenar')
            ->assertDontSee('Mover para antes')
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
        $this->area('População')->widgets()->update(['largura' => 1]);

        $html = $this->painel()->call('alternarEdicao')->html();

        $this->assertSame(4, substr_count($html, 'aria-pressed="true"'), 'exatamente um botão de largura marcado por visual da área (são 4)');
    }

    public function test_area_vazia_convida_a_adicionar_visuais(): void
    {
        $this->painel()->call('novaArea')->set('areaNome', 'Vazia')->call('salvarArea')
            ->assertSee('A área “Vazia” está vazia')
            ->assertSee('Personalizar painel')
            ->call('alternarEdicao')
            ->assertSee('para escolher o que acompanhar nesta área');
    }

    public function test_galeria_lista_por_dimensao_filtra_e_marca_quais_visuais_ja_estao_na_area(): void
    {
        $this->painel()
            ->call('alternarEdicao')
            ->call('abrirGaleria')
            ->assertSee('Visuais da área “População”')
            ->assertSee('Desempenho da APS')
            ->assertSee('Necessidade da APS')
            ->assertSee('Internações por condições sensíveis à APS')
            ->assertSee('(na área; clique para remover)')
            ->assertSee('(clique para adicionar)')
            ->assertSee('4 visuais')
            ->set('busca', 'internações')
            ->assertSee('Internações por condições sensíveis à APS')
            ->assertDontSee('Cobertura da Estratégia Saúde da Família')
            ->set('busca', 'zzz')
            ->assertSee('Nenhum indicador encontrado');
    }

    public function test_na_galeria_os_visuais_da_area_aparecem_marcados_e_dá_para_desmarcar(): void
    {
        $pagina = $this->painel()->call('alternarEdicao')->call('abrirGaleria');
        $html = $pagina->html();

        // População: 4 visuais da área (destaque, evolução, ranking, mapa) estão marcados.
        $this->assertSame(4, substr_count($html, '(na área; clique para remover)'));

        $pagina->call('alternarVisual', 'destaque', 'populacao_total');

        $this->assertSame(3, substr_count($pagina->html(), '(na área; clique para remover)'));
        $this->assertSame(3, $this->area('População')->widgets()->count());
    }

    public function test_galeria_so_oferece_indicadores_ativos(): void
    {
        $this->icsap->update(['ativo' => false]);

        $this->painel()->call('alternarEdicao')->call('abrirGaleria')->assertDontSee('Internações por condições sensíveis à APS');
    }

    public function test_formulario_de_nova_area_oferece_as_areas_prontas_como_ponto_de_partida(): void
    {
        $this->painel()->call('alternarEdicao')->call('novaArea')
            ->assertSee('Nova área')
            ->assertSee('Começar com')
            ->assertSee('Área vazia')
            ->assertSee('Cobertura e estrutura')
            ->assertSee('4 visuais');
    }

    public function test_largura_define_as_colunas_ocupadas(): void
    {
        $this->painel();
        $this->area('População')->widgets()->where('tipo', 'evolucao')->update(['largura' => 3]);

        $this->painel()->assertSeeHtml('md:col-span-2 lg:col-span-3');
    }
}
