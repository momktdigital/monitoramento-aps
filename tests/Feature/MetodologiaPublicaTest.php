<?php

namespace Tests\Feature;

use App\Models\Integracao;
use App\Models\Metodologia;
use App\Models\User;
use Database\Seeders\IndicadorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MetodologiaPublicaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IndicadorSeeder::class);
    }

    public function test_a_pagina_e_publica_e_explica_os_indices_sem_exigir_login(): void
    {
        $this->get('/metodologia')
            ->assertOk()
            ->assertSee('Metodologia e fontes')
            ->assertSee('INA · Índice de Necessidade da APS')
            ->assertSee('IDAPS · Índice de Desempenho da APS')
            ->assertSee('IPF · Índice de Potencial de Financiamento')
            ->assertSee('Pesos em validação')
            ->assertSee('Entrar');
    }

    public function test_mostra_pilares_pesos_e_o_estado_de_cada_indicador(): void
    {
        $this->get('/metodologia')
            ->assertSee('Estrutura e cobertura')
            ->assertSee('Resultados em saúde')
            ->assertSee('35% do IDAPS')
            ->assertSee('40% do IDAPS')
            ->assertSee('obrigatório: sem ele o índice não é calculado')
            ->assertSee('Quanto menor o valor, maior a nota')
            ->assertSee('aguardando dados');
    }

    public function test_pesos_vem_da_metodologia_ativa_e_mudam_com_a_versao(): void
    {
        config(['indices.idaps.pilares.estrutura.peso' => 70]);
        Metodologia::sincronizar();

        $this->get('/metodologia')->assertSee('Metodologia em uso: versão 1')->assertSee('52% do IDAPS');
    }

    public function test_explica_os_quadrantes_a_regra_de_efetividade_e_os_limites(): void
    {
        $this->get('/metodologia')
            ->assertSee('Prioridade máxima')
            ->assertSee('Estrutura consolidada')
            ->assertSee('a partir de 60')
            ->assertSee('nunca vira zero')
            ->assertSee('As notas são relativas')
            ->assertSee('Moradores que se internam em outro estado não aparecem');
    }

    public function test_lista_as_fontes_com_a_data_da_ultima_atualizacao_sem_expor_detalhes_internos(): void
    {
        Integracao::factory()->daFonte('ibge')->create([
            'ultimo_sucesso_em' => Carbon::parse('2026-10-05 08:00', 'UTC'),
            'ultimo_erro' => 'segredo-interno-do-erro',
            'config' => ['chave_api' => 'chave-super-secreta'],
        ]);

        $this->get('/metodologia')
            ->assertSee('IBGE')
            ->assertSee('Última atualização: 05/10/2026')
            ->assertSee('Ainda sem atualização')
            ->assertDontSee('segredo-interno-do-erro')
            ->assertDontSee('chave-super-secreta');
    }

    public function test_traz_o_catalogo_com_os_quatro_textos_de_ajuda(): void
    {
        $this->get('/metodologia')
            ->assertSee('Catálogo de indicadores')
            ->assertSee('Cobertura da Estratégia Saúde da Família')
            ->assertSee('Como interpretar')
            ->assertSee('Índice de Desempenho da APS (IDAPS)');
    }

    public function test_indicadores_ocultos_nao_aparecem_no_catalogo_publico(): void
    {
        $this->get('/metodologia')->assertDontSee('Internações exceto obstétricas no mês (contagem)');
    }

    public function test_quem_esta_logado_ve_a_pagina_dentro_do_layout_do_sistema(): void
    {
        $this->actingAs(User::factory()->gestor()->create())
            ->get('/metodologia')
            ->assertOk()
            ->assertSee('Metodologia e fontes')
            ->assertSee('Navegação principal')
            ->assertSee('Matriz de prioridade')
            ->assertDontSee('Esta página é pública');
    }

    public function test_a_pagina_publica_leva_os_cabecalhos_de_seguranca(): void
    {
        $this->get('/metodologia')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
