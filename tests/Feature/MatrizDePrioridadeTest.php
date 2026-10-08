<?php

namespace Tests\Feature;

use App\Enums\QuadranteIpf;
use App\Livewire\Analises\Matriz;
use App\Models\IndiceMunicipio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CriaCenarioDeIndices;
use Tests\TestCase;

class MatrizDePrioridadeTest extends TestCase
{
    use CriaCenarioDeIndices;
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->criarCenarioDeIndices();
        $this->usuario = User::factory()->gestor()->create();
    }

    private function pagina(): Testable
    {
        Livewire::withoutLazyLoading();

        return Livewire::actingAs($this->usuario)->test(Matriz::class);
    }

    public function test_a_pagina_exige_login(): void
    {
        $this->get('/matriz')->assertRedirect('/login');
    }

    public function test_usuario_autenticado_abre_a_matriz_e_ela_aparece_no_menu(): void
    {
        $this->actingAs($this->usuario)->get('/matriz')->assertOk()->assertSee('Matriz de prioridade')->assertSee('Comparar municípios')->assertSee('Mapa')->assertSee('Metodologia');
    }

    public function test_sem_indices_calculados_mostra_estado_vazio_com_orientacao(): void
    {
        IndiceMunicipio::query()->delete();

        $this->pagina()
            ->assertSee('Os índices ainda não foram calculados')
            ->assertSee('Ler a metodologia');
    }

    public function test_abre_no_mes_mais_recente_com_o_municipio_em_foco_e_sua_analise(): void
    {
        $this->pagina()
            ->assertSet('municipioId', 3306107)
            ->assertSee('jul/2026')
            ->assertSee('Análise do cenário atual')
            ->assertSee('Em Valença, a necessidade da população (INA) é 80 e o desempenho da Atenção Primária (IDAPS) é 30')
            ->assertSee('Isso coloca Valença em “Prioridade máxima”')
            ->assertSee('2ª posição entre 6 municípios')
            ->assertSee('Desde jun/2026, o IDAPS subiu 5 pontos')
            ->assertSee('Ponto de atenção: a estrutura e a cobertura (nota 70)')
            ->assertSee('Merece atenção');
    }

    public function test_ranking_ordena_por_prioridade_e_mostra_o_quadrante_de_cada_municipio(): void
    {
        $pagina = $this->pagina();

        $this->assertSame(
            ['Macaé', 'Valença', 'Volta Redonda', 'Resende', 'Cabo Frio', 'Niterói'],
            $pagina->viewData('ranking')->map(fn ($linha) => $linha->municipio->nome)->all(),
        );

        $pagina->assertSee('Oportunidade moderada')->assertSee('Grande potencial')->assertSee('estrutura sem resultado');
    }

    public function test_dados_do_grafico_trazem_os_pontos_os_cortes_e_o_municipio_em_foco(): void
    {
        $grafico = $this->pagina()->viewData('grafico');

        $this->assertSame('matriz', $grafico['modo']);
        $this->assertCount(6, $grafico['pontos']);
        $this->assertSame(3306107, $grafico['selecionado']);
        $this->assertEqualsWithDelta(55.0, $grafico['cortes']['ina'], 0.001, 'mediana de 90, 80, 40, 70, 30 e 20');
        $this->assertEqualsWithDelta(50.0, $grafico['cortes']['idaps'], 0.001, 'mediana de 10, 30, 20, 70, 80 e 90');
        $this->assertSame(QuadranteIpf::PrioridadeMaxima->value, collect($grafico['pontos'])->firstWhere('id', 3306107)['quadrante']);
    }

    public function test_filtros_do_ranking_por_quadrante_e_por_regiao(): void
    {
        $nomes = fn ($pagina) => $pagina->viewData('ranking')->map(fn ($linha) => $linha->municipio->nome)->sort()->values()->all();

        $pagina = $this->pagina()->set('quadrante', QuadranteIpf::EstruturaConsolidada->value);
        $this->assertSame(['Cabo Frio', 'Niterói'], $nomes($pagina));

        $pagina->set('quadrante', 0)->set('regiao', 'MEDIO PARAIBA');
        $this->assertSame(['Resende', 'Valença', 'Volta Redonda'], $nomes($pagina));
    }

    public function test_filtro_de_quadrante_invalido_volta_para_todos(): void
    {
        $this->pagina()->set('quadrante', 99)->assertSet('quadrante', 0);
    }

    public function test_selecionar_um_municipio_muda_o_foco_e_lembra_a_escolha(): void
    {
        $this->pagina()
            ->call('selecionar', $this->municipiosDosIndices['cabo_frio']->id)
            ->assertSet('municipioId', 3300704)
            ->assertSee('Em Cabo Frio, a necessidade da população (INA) é 30')
            ->assertSee('Estrutura consolidada');

        $this->assertSame(3300704, $this->usuario->fresh()->municipio_id);
    }

    public function test_selecionar_municipio_inexistente_nao_muda_o_foco(): void
    {
        $this->pagina()->call('selecionar', 999)->assertSet('municipioId', 3306107);
    }

    public function test_mes_de_referencia_invalido_cai_no_mais_recente_e_o_anterior_pode_ser_escolhido(): void
    {
        $this->pagina()->set('competencia', 209901)->assertSee('Mês de referência: jul/2026');

        $this->pagina()->set('competencia', 202606)->assertSee('Mês de referência: jun/2026')->assertSee('Em Valença, a necessidade da população (INA) é 80 e o desempenho da Atenção Primária (IDAPS) é 25');
    }

    public function test_sem_ina_mostra_o_ranking_de_desempenho_e_explica_por_que_nao_ha_matriz(): void
    {
        IndiceMunicipio::query()->update(['ina' => null, 'ipf_quadrante' => null, 'ipf_pontuacao' => null]);

        $this->pagina()
            ->assertSee('0 de 6 municípios')
            ->assertSee('Ranking de desempenho da Atenção Primária (IDAPS)')
            ->assertSee('ainda não aparece na matriz porque a necessidade da população (INA) não pôde ser calculada')
            ->assertDontSee('Ranking de prioridade de apoio');
    }

    public function test_sem_ina_o_ranking_de_desempenho_vai_do_melhor_para_o_pior(): void
    {
        IndiceMunicipio::query()->update(['ina' => null, 'ipf_quadrante' => null, 'ipf_pontuacao' => null]);

        $this->assertSame(
            ['Niterói', 'Cabo Frio', 'Resende', 'Valença', 'Volta Redonda', 'Macaé'],
            $this->pagina()->viewData('rankingDeDesempenho')->map(fn ($linha) => $linha->municipio->nome)->all(),
        );
    }

    public function test_aviso_de_confianca_aparece_quando_o_indice_usou_parte_da_metodologia(): void
    {
        IndiceMunicipio::query()->where('municipio_id', 3306107)->update(['confianca_idaps' => 0.8]);

        $this->pagina()->assertSee('o cálculo usou cerca de 80% da metodologia');
    }
}
