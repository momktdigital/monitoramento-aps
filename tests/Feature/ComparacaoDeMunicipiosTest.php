<?php

namespace Tests\Feature;

use App\Enums\Dimensao;
use App\Enums\Periodicidade;
use App\Enums\Polaridade;
use App\Livewire\Analises\Comparar;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Models\User;
use App\Models\ValorIndicador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CriaCenarioDeIndices;
use Tests\TestCase;

class ComparacaoDeMunicipiosTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $parametros
     */
    private function pagina(array $parametros = []): Testable
    {
        Livewire::withoutLazyLoading();

        return Livewire::actingAs($this->usuario)->withQueryParams($parametros)->test(Comparar::class);
    }

    private Indicador $coberturaEsf;

    private Indicador $icsap;

    private Indicador $populacao;

    private function criarIndicadores(): void
    {
        $this->coberturaEsf = Indicador::factory()->create(['codigo' => 'cobertura_esf', 'nome' => 'Cobertura da Estratégia Saúde da Família', 'unidade' => '%', 'polaridade' => Polaridade::MaiorMelhor, 'periodicidade' => Periodicidade::Mensal, 'dimensao' => Dimensao::Desempenho, 'ordem' => 1]);
        $this->icsap = Indicador::factory()->create(['codigo' => 'icsap_taxa', 'nome' => 'Internações por condições sensíveis à APS', 'unidade' => 'por 10 mil hab.', 'polaridade' => Polaridade::MenorMelhor, 'periodicidade' => Periodicidade::Mensal, 'dimensao' => Dimensao::Desempenho, 'ordem' => 2]);
        $this->populacao = Indicador::factory()->create(['codigo' => 'populacao_total', 'nome' => 'População residente', 'unidade' => 'pessoas', 'casas_decimais' => 0, 'polaridade' => Polaridade::Neutra, 'periodicidade' => Periodicidade::Anual, 'dimensao' => Dimensao::Necessidade, 'ordem' => 3]);
    }

    private function gravarValor(Indicador $indicador, string $municipio, array $valoresPorCompetencia): void
    {
        ValorIndicador::gravarEmLote(array_map(
            fn (int $competencia, float $valor): array => ['municipio_id' => $this->municipiosDosIndices[$municipio]->id, 'indicador_id' => $indicador->id, 'competencia' => $competencia, 'valor' => $valor],
            array_keys($valoresPorCompetencia),
            $valoresPorCompetencia,
        ));
    }

    private function idsDa(Testable $pagina): array
    {
        return $pagina->get('ids');
    }

    public function test_a_pagina_exige_login(): void
    {
        $this->get('/comparar')->assertRedirect('/login');
    }

    public function test_comeca_com_o_municipio_em_foco_e_vizinhos_da_mesma_regiao_de_saude(): void
    {
        $pagina = $this->pagina();

        $this->assertSame([3306107, 3304201, 3306305], $this->idsDa($pagina), 'Valença e os outros dois da região (Resende, Volta Redonda)');
        $pagina->assertSee('Valença')->assertSee('Resende')->assertSee('Volta Redonda');
    }

    public function test_municipios_do_endereco_substituem_o_padrao_e_ids_invalidos_sao_ignorados(): void
    {
        $pagina = $this->pagina(['municipios' => [3300704, 3303302, 999, 'abc', 3300704]]);

        $this->assertSame([3300704, 3303302], $this->idsDa($pagina));
    }

    public function test_aceita_no_maximo_quatro_municipios(): void
    {
        $pagina = $this->pagina(['municipios' => [3306107, 3304201, 3306305, 3300704, 3303302]]);

        $this->assertCount(4, $this->idsDa($pagina));

        $pagina->call('adicionar', 3302403);

        $this->assertCount(4, $this->idsDa($pagina));
        $pagina->assertSee('Limite de 4 municípios');
    }

    public function test_adicionar_pelo_seletor_inclui_o_municipio_e_ignora_repetidos_e_inexistentes(): void
    {
        $pagina = $this->pagina(['municipios' => [3306107, 3304201]]);

        $pagina->set('novo', 3300704)->assertSet('novo', 0);
        $this->assertSame([3306107, 3304201, 3300704], $this->idsDa($pagina));

        $pagina->call('adicionar', 3300704)->call('adicionar', 123);
        $this->assertSame([3306107, 3304201, 3300704], $this->idsDa($pagina));
    }

    public function test_remover_tira_o_municipio_da_comparacao(): void
    {
        $pagina = $this->pagina(['municipios' => [3306107, 3304201, 3306305]]);

        $pagina->call('remover', 3304201);

        $this->assertSame([3306107, 3306305], $this->idsDa($pagina));
    }

    public function test_cada_municipio_mantem_a_cor_enquanto_estiver_na_comparacao(): void
    {
        $pagina = $this->pagina(['municipios' => [3306107, 3304201, 3306305]]);

        $this->assertSame([3306107 => 0, 3304201 => 1, 3306305 => 2], $pagina->get('cores'));

        $pagina->call('remover', 3306107);
        $this->assertSame([3304201 => 1, 3306305 => 2], $pagina->get('cores'), 'quem ficou não troca de cor');

        $pagina->call('adicionar', 3300704);
        $this->assertSame(0, $pagina->get('cores')[3300704], 'o novo ocupa a cor que ficou livre');
    }

    public function test_ids_e_cores_nao_podem_ser_alterados_pelo_navegador(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->pagina()->set('ids', [1, 2, 3]);
    }

    public function test_grafico_e_tabela_trazem_as_notas_dos_indices_do_mes_mais_recente(): void
    {
        $pagina = $this->pagina(['municipios' => [3306107, 3304201]]);
        $grafico = $pagina->viewData('grafico');

        $this->assertSame('comparacao', $grafico['modo']);
        $this->assertSame(['Necessidade (INA)', 'Desempenho (IDAPS)', 'Estrutura e cobertura', 'Resultados em saúde'], $grafico['categorias']);
        $this->assertEquals([80.0, 30.0, 70.0, 20.0], $grafico['series'][0]['dados']);
        $this->assertSame(0, $grafico['series'][0]['cor']);
        $this->assertSame('Resende', $grafico['series'][1]['nome']);

        $pagina->assertSee('Mês de referência: jul/2026')->assertSee('Prioridade máxima')->assertSee('Grande potencial');
    }

    public function test_analise_compara_os_indices_e_cita_os_quadrantes(): void
    {
        $this->pagina(['municipios' => [3306107, 3304201]])
            ->assertSee('Análise do cenário atual')
            ->assertSee('Quanto ao desempenho da Atenção Primária (IDAPS) em jul/2026, Resende tem a maior nota (70) e Valença a menor (30): uma diferença de 40 pontos')
            ->assertSee('Na matriz INA × IDAPS: Valença em “Prioridade máxima”; Resende em “Grande potencial”.');
    }

    public function test_com_menos_de_dois_municipios_a_analise_pede_mais_um(): void
    {
        $this->pagina(['municipios' => [3306107]])->assertSee('Escolha pelo menos dois municípios');
    }

    public function test_tabela_de_indicadores_marca_a_melhor_situacao_so_quando_ha_sentido_e_sem_empate(): void
    {
        $this->criarIndicadores();
        $this->gravarValor($this->coberturaEsf, 'valenca', [202607 => 88.0]);
        $this->gravarValor($this->coberturaEsf, 'resende', [202608 => 90.0]);
        $this->gravarValor($this->icsap, 'valenca', [202607 => 30.0]);
        $this->gravarValor($this->icsap, 'resende', [202607 => 20.0]);
        $this->gravarValor($this->populacao, 'valenca', [202512 => 71000.0]);
        $this->gravarValor($this->populacao, 'resende', [202512 => 130000.0]);

        $pagina = $this->pagina(['municipios' => [3306107, 3304201]]);
        $melhores = $pagina->viewData('melhores');

        $this->assertSame(3304201, $melhores[$this->coberturaEsf->id], 'Resende: 90 contra 88 de Valença');
        $this->assertSame(3304201, $melhores[$this->icsap->id], 'ICSAP: menos é melhor (20 contra 30)');
        $this->assertArrayNotHasKey($this->populacao->id, $melhores, 'população é informativa: não tem melhor');

        $pagina->assertSee('Cobertura da Estratégia Saúde da Família')->assertSee('88,0%')->assertSee('90,0%')->assertSee('(melhor situação)');
    }

    public function test_empate_no_topo_nao_marca_ninguem(): void
    {
        $this->criarIndicadores();
        $this->gravarValor($this->coberturaEsf, 'valenca', [202607 => 88.0]);
        $this->gravarValor($this->coberturaEsf, 'resende', [202607 => 88.0]);

        $this->assertArrayNotHasKey($this->coberturaEsf->id, $this->pagina(['municipios' => [3306107, 3304201]])->viewData('melhores'));
    }

    public function test_a_analise_cita_o_indicador_de_maior_diferenca(): void
    {
        $this->criarIndicadores();
        $this->gravarValor($this->icsap, 'valenca', [202607 => 30.0]);
        $this->gravarValor($this->icsap, 'resende', [202607 => 15.0]);

        $this->pagina(['municipios' => [3306107, 3304201]])
            ->assertSee('a maior diferença está em “Internações por condições sensíveis à APS”: Resende tem 15,0 por 10 mil hab., enquanto Valença tem 30,0 por 10 mil hab.');
    }

    public function test_indicadores_calculados_ficam_fora_da_tabela_de_indicadores(): void
    {
        Indicador::factory()->create(['codigo' => 'indice_idaps', 'nome' => 'Índice de Desempenho da APS (IDAPS)']);
        ValorIndicador::gravarEmLote([['municipio_id' => 3306107, 'indicador_id' => Indicador::where('codigo', 'indice_idaps')->value('id'), 'competencia' => 202607, 'valor' => 30]]);

        $this->assertSame([], array_values($this->pagina(['municipios' => [3306107, 3304201]])->viewData('grupos')->flatten()->pluck('codigo')->all()));
    }

    public function test_municipio_sem_indices_nao_derruba_a_pagina(): void
    {
        $semDados = Municipio::factory()->create(['id' => 3301009, 'codigo6' => 330100]);

        $this->pagina(['municipios' => [3306107, $semDados->id]])->assertOk()->assertSee($semDados->nome);
    }
}
