<?php

namespace Tests\Feature;

use App\Enums\Dimensao;
use App\Enums\Periodicidade;
use App\Enums\Polaridade;
use App\Livewire\Analises\Mapa;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Models\User;
use App\Models\ValorIndicador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class MapaDosMunicipiosTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Indicador $cobertura;

    private Indicador $icsap;

    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->gestor()->create();

        $this->cobertura = Indicador::factory()->create(['codigo' => 'cobertura_esf', 'nome' => 'Cobertura da Estratégia Saúde da Família', 'unidade' => '%', 'polaridade' => Polaridade::MaiorMelhor, 'periodicidade' => Periodicidade::Mensal, 'dimensao' => Dimensao::Desempenho, 'ordem' => 1]);
        $this->icsap = Indicador::factory()->create(['codigo' => 'icsap_taxa', 'nome' => 'Internações por condições sensíveis à APS', 'unidade' => 'por 10 mil hab.', 'polaridade' => Polaridade::MenorMelhor, 'periodicidade' => Periodicidade::Mensal, 'dimensao' => Dimensao::Desempenho, 'ordem' => 2]);

        // Dez municípios com cobertura de 10 a 100; o 10º (id 3300010) não tem ICSAP.
        for ($i = 1; $i <= 10; $i++) {
            $id = 3300000 + $i * 100 + 7;
            Municipio::factory()->create(['id' => $id, 'codigo6' => $id % 1000000, 'nome' => "Município {$i}", 'piloto' => $i === 1]);
            $this->ids[] = $id;

            ValorIndicador::gravarEmLote([['municipio_id' => $id, 'indicador_id' => $this->cobertura->id, 'competencia' => 202607, 'valor' => $i * 10]]);

            if ($i < 10) {
                ValorIndicador::gravarEmLote([['municipio_id' => $id, 'indicador_id' => $this->icsap->id, 'competencia' => 202607, 'valor' => 100 - $i * 5]]);
            }
        }

        $this->usuario->forceFill(['municipio_id' => $this->ids[0]])->save();
    }

    /**
     * @param  array<string, mixed>  $parametros
     */
    private function pagina(array $parametros = []): Testable
    {
        Livewire::withoutLazyLoading();

        return Livewire::actingAs($this->usuario->fresh())->withQueryParams($parametros)->test(Mapa::class);
    }

    public function test_a_pagina_exige_login(): void
    {
        $this->get('/mapa')->assertRedirect('/login');
    }

    public function test_sem_nenhum_dado_mostra_estado_vazio(): void
    {
        ValorIndicador::query()->delete();

        $this->pagina()->assertSee('Ainda não há dados para desenhar o mapa');
    }

    public function test_sem_indicador_escolhido_usa_o_primeiro_com_dados_e_mostra_valor_analise_e_fonte(): void
    {
        $this->pagina()
            ->assertSet('indicador', 'cobertura_esf')
            ->assertSee('Cobertura da Estratégia Saúde da Família')
            ->assertSee('Dado de jul/2026')
            ->assertSee('Análise do cenário atual')
            ->assertSee('Em jul/2026, “Cobertura da Estratégia Saúde da Família” vai de 10,0% (Município 1) a 100,0% (Município 10) entre os 10 municípios com dado. A mediana do estado é 55,0%.')
            ->assertSee('Município 1 tem 10,0%, abaixo da mediana do estado.')
            ->assertSee('É a 10ª posição entre 10 municípios (a 1ª é a melhor situação).')
            ->assertSee('Merece atenção');
    }

    public function test_indicador_do_endereco_e_aceito_e_codigo_invalido_cai_no_padrao(): void
    {
        $this->pagina(['indicador' => 'icsap_taxa'])->assertSet('indicador', 'icsap_taxa')->assertSee('Internações por condições sensíveis à APS');
        $this->pagina(['indicador' => 'nao_existe'])->assertSet('indicador', 'cobertura_esf');
    }

    public function test_dados_do_mapa_tem_uma_regiao_por_municipio_cor_da_classe_e_o_foco_por_ultimo(): void
    {
        $mapa = $this->pagina()->viewData('grafico');

        $this->assertSame('mapa', $mapa['modo']);
        $this->assertSame('uf-33', $mapa['mapa']);
        $this->assertStringContainsString('/dados/malha/33', $mapa['malha']);
        $this->assertCount(10, $mapa['regioes']);
        $this->assertSame('3300107', $mapa['selecionado']);
        $this->assertSame('3300107', $mapa['regioes'][array_key_last($mapa['regioes'])]['name'], 'o município em foco é desenhado por último, com o contorno por cima');
        $this->assertSame(0, $mapa['sem_dado']);
        $this->assertCount(5, $mapa['faixas']);
        $this->assertSame('#cde2fb', $mapa['faixas'][0]['cor']);
        $this->assertSame('#184f95', $mapa['faixas'][4]['cor']);

        $cores = collect($mapa['regioes'])->keyBy('name')->map->cor;
        $this->assertSame('#cde2fb', $cores['3300107'], 'menor cobertura: classe mais clara');
        $this->assertSame('#184f95', $cores['3301007'], 'maior cobertura: classe mais escura');
    }

    public function test_municipio_sem_dado_aparece_em_cinza_e_avisa_na_legenda_e_na_analise(): void
    {
        $pagina = $this->pagina(['indicador' => 'icsap_taxa']);
        $mapa = $pagina->viewData('grafico');

        $this->assertSame(1, $mapa['sem_dado']);
        $this->assertSame('#e8e6df', collect($mapa['regioes'])->firstWhere('name', '3301007')['cor']);
        $this->assertSame('Sem dado nesta competência', collect($mapa['regioes'])->firstWhere('name', '3301007')['texto']);

        $pagina->assertSee('Sem dado')->assertSee('1 município está sem dado nesta competência e aparece em cinza no mapa.');
    }

    public function test_indicador_em_que_menos_e_melhor_ordena_a_tabela_do_menor_para_o_maior(): void
    {
        $tabela = $this->pagina(['indicador' => 'icsap_taxa'])->viewData('tabela');

        $this->assertSame('Município 9', $tabela['linhas'][0][0], 'ICSAP 55 é o menor valor: melhor situação');
        $this->assertSame('Município 1', $tabela['linhas'][8][0]);
        $this->assertCount(9, $tabela['ids']);
    }

    public function test_clicar_em_um_municipio_coloca_o_em_foco_e_lembra_a_escolha(): void
    {
        $this->pagina()->call('selecionar', $this->ids[4])->assertSet('municipioId', $this->ids[4])->assertSee('Município 5 tem 50,0%, abaixo da mediana do estado.');

        $this->assertSame($this->ids[4], $this->usuario->fresh()->municipio_id);
    }

    public function test_so_oferece_indicadores_ativos_visiveis_e_com_dados(): void
    {
        Indicador::factory()->create(['codigo' => 'sem_dados', 'nome' => 'Indicador sem dados']);
        Indicador::factory()->create(['codigo' => 'oculto', 'nome' => 'Indicador oculto', 'visivel' => false]);
        Indicador::factory()->create(['codigo' => 'inativo', 'nome' => 'Indicador inativo', 'ativo' => false]);

        $codigos = $this->pagina()->viewData('opcoes')->flatten()->pluck('codigo')->all();

        $this->assertSame(['cobertura_esf', 'icsap_taxa'], $codigos);
    }

    public function test_usa_a_competencia_mais_recente_que_cobre_ao_menos_metade_dos_municipios(): void
    {
        // jul/2026 tem 10 municípios; ago/2026 só tem 1 de 10: o mapa continua em jul.
        ValorIndicador::gravarEmLote([['municipio_id' => $this->ids[0], 'indicador_id' => $this->cobertura->id, 'competencia' => 202608, 'valor' => 99]]);

        $this->pagina()->assertSee('Dado de jul/2026');
    }
}
