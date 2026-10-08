<?php

namespace Tests\Feature;

use App\Domain\Scoring\CalculadorDeIndices;
use App\Enums\Dimensao;
use App\Enums\Periodicidade;
use App\Enums\Polaridade;
use App\Enums\QuadranteIpf;
use App\Enums\TipoDeVisual;
use App\Livewire\Painel\Visual;
use App\Models\Benchmark;
use App\Models\Indicador;
use App\Models\IndiceMunicipio;
use App\Models\Metodologia;
use App\Models\Municipio;
use App\Models\PainelWidget;
use App\Models\User;
use App\Models\ValorIndicador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cenário pequeno, com resultado calculável à mão: 5 municípios do RJ, metodologia compacta.
 *
 * Valores em jul/2026 (m1 a m5) e as notas que eles geram entre os pares:
 *   cobertura (maior melhor)  10 20 30 40 50  => estrutura  0   25   50   75  100
 *   icsap     (menor melhor)  50 40 30 20 60  => resultado 25   50   75  100    0
 *   IDAPS (média dos pilares)                      12,5 37,5 62,5 87,5 50
 *   pobreza   (alto = precisa) 25 20 15 10 5  => nota      100  75   50   25    0
 *   renda     (baixo = precisa) 100..500      => nota      100  75   50   25    0
 *   INA (média dos pilares)                        100   75   50   25    0
 */
class CalculadorDeIndicesTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCIA = 202607;

    /** @var list<int> */
    private array $ids = [3300101, 3300102, 3300103, 3300104, 3300105];

    private Indicador $cobertura;

    private Indicador $icsap;

    private Indicador $pobreza;

    private Indicador $renda;

    protected function setUp(): void
    {
        parent::setUp();

        config(['indices' => $this->metodologiaCompacta()]);

        $this->cobertura = $this->indicador('cobertura', Periodicidade::Mensal, Polaridade::MaiorMelhor, Dimensao::Desempenho);
        $this->icsap = $this->indicador('icsap', Periodicidade::Mensal, Polaridade::MenorMelhor, Dimensao::Desempenho);
        $this->pobreza = $this->indicador('pobreza', Periodicidade::Anual, Polaridade::MenorMelhor, Dimensao::Necessidade);
        $this->renda = $this->indicador('renda', Periodicidade::Anual, Polaridade::MaiorMelhor, Dimensao::Necessidade);

        foreach (['indice_ina', 'indice_idaps', 'indice_prioridade'] as $codigo) {
            $this->indicador($codigo, Periodicidade::Mensal, Polaridade::Neutra, Dimensao::Indices);
        }

        foreach ($this->ids as $posicao => $id) {
            Municipio::factory()->create(['id' => $id, 'codigo6' => $id % 1000000, 'nome' => 'Município '.($posicao + 1)]);
        }

        foreach ([202605, 202606, 202607] as $mes) {
            $this->gravar($this->cobertura, [10, 20, 30, 40, 50], $mes);
            $this->gravar($this->icsap, [50, 40, 30, 20, 60], $mes);
        }

        $this->gravar($this->pobreza, [25, 20, 15, 10, 5], 202212);
        $this->gravar($this->renda, [100, 200, 300, 400, 500], 202512);
    }

    /**
     * @return array<string, mixed>
     */
    private function metodologiaCompacta(): array
    {
        return [
            'pares' => 'uf',
            'janela_meses' => 3,
            'ancora' => 'cobertura',
            'minimo_de_pares' => 4,
            'cobertura_minima_dos_pares' => 0.8,
            'confianca_minima' => 0.7,
            'confianca_alta' => 0.9,
            'validade_meses' => ['mensal' => 2, 'quadrimestral' => 12, 'anual' => 36],
            'ina' => ['pilares' => ['vulnerabilidade' => [
                'peso' => 1,
                'obrigatorio' => true,
                'componentes' => [
                    'pobreza' => ['peso' => 1, 'sentido' => 'alto', 'validade_meses' => 120],
                    'renda' => ['peso' => 1, 'sentido' => 'baixo'],
                ],
            ]]],
            'idaps' => ['pilares' => [
                'estrutura' => ['peso' => 1, 'obrigatorio' => true, 'componentes' => ['cobertura' => ['peso' => 1, 'sentido' => 'alto']]],
                'resultado' => ['peso' => 1, 'obrigatorio' => true, 'componentes' => ['icsap' => ['peso' => 1, 'sentido' => 'baixo']]],
            ]],
            'ipf' => ['corte' => 'mediana', 'quadrantes' => [
                'necessidade_alta_desempenho_baixo' => 'prioridade_maxima',
                'necessidade_alta_desempenho_alto' => 'grande_potencial',
                'necessidade_baixa_desempenho_baixo' => 'oportunidade_moderada',
                'necessidade_baixa_desempenho_alto' => 'estrutura_consolidada',
            ]],
            'efetividade' => ['pilar_estrutura' => 'estrutura', 'pilar_resultado' => 'resultado', 'estrutura_minima' => 60, 'resultado_maximo' => 40],
        ];
    }

    private function indicador(string $codigo, Periodicidade $periodicidade, Polaridade $polaridade, Dimensao $dimensao): Indicador
    {
        return Indicador::factory()->create(['codigo' => $codigo, 'periodicidade' => $periodicidade, 'polaridade' => $polaridade, 'dimensao' => $dimensao]);
    }

    /**
     * @param  list<int|float>  $valores  um por município (m1 a m5)
     * @param  list<int>|null  $municipios
     */
    private function gravar(Indicador $indicador, array $valores, int $competencia, ?array $municipios = null): void
    {
        $municipios ??= $this->ids;

        ValorIndicador::gravarEmLote(array_map(
            fn (int $municipio, int|float $valor): array => ['municipio_id' => $municipio, 'indicador_id' => $indicador->id, 'competencia' => $competencia, 'valor' => $valor],
            $municipios,
            array_slice($valores, 0, count($municipios)),
        ));
    }

    /**
     * @return Collection<int, IndiceMunicipio> indexados pelo município
     */
    private function indicesDe(int $competencia): Collection
    {
        return IndiceMunicipio::query()->where('competencia', $competencia)->orderBy('municipio_id')->get()->keyBy('municipio_id');
    }

    private function calcular(): array
    {
        return app(CalculadorDeIndices::class)->calcular();
    }

    public function test_calcula_notas_pilares_e_indices_no_mes_de_referencia(): void
    {
        $resultado = $this->calcular();

        $this->assertSame(15, $resultado['linhas'], '5 municípios × 3 meses');
        $this->assertSame(self::REFERENCIA, $resultado['ultima_competencia']);

        $indices = $this->indicesDe(self::REFERENCIA);

        $this->assertEqualsWithDelta([12.5, 37.5, 62.5, 87.5, 50.0], $indices->pluck('idaps')->values()->all(), 0.001);
        $this->assertEqualsWithDelta([100.0, 75.0, 50.0, 25.0, 0.0], $indices->pluck('ina')->values()->all(), 0.001);
        $this->assertEqualsWithDelta([0.0, 25.0, 50.0, 75.0, 100.0], $indices->pluck('idaps_estrutura')->values()->all(), 0.001);
        $this->assertEqualsWithDelta([25.0, 50.0, 75.0, 100.0, 0.0], $indices->pluck('idaps_resultado')->values()->all(), 0.001);
    }

    public function test_valor_baixo_desejavel_inverte_a_nota(): void
    {
        $this->calcular();

        $m4 = $this->indicesDe(self::REFERENCIA)[3300104];

        // m4 tem a menor ICSAP (20): é o melhor, então a nota de resultado é a máxima.
        $this->assertEqualsWithDelta(100.0, $m4->idaps_resultado, 0.001);
        $this->assertEqualsWithDelta(100.0, $m4->detalhes['idaps']['componentes']['icsap']['nota'], 0.1);
        $this->assertEqualsWithDelta(20.0, $m4->detalhes['idaps']['componentes']['icsap']['valor'], 0.001);
    }

    public function test_matriz_ipf_usa_a_mediana_do_mes_e_a_pontuacao_de_prioridade(): void
    {
        $this->calcular();

        $indices = $this->indicesDe(self::REFERENCIA);

        $this->assertSame(QuadranteIpf::PrioridadeMaxima, $indices[3300101]->ipf_quadrante);
        $this->assertSame(QuadranteIpf::PrioridadeMaxima, $indices[3300102]->ipf_quadrante);
        $this->assertSame(QuadranteIpf::GrandePotencial, $indices[3300103]->ipf_quadrante);
        $this->assertSame(QuadranteIpf::EstruturaConsolidada, $indices[3300104]->ipf_quadrante);
        $this->assertSame(QuadranteIpf::EstruturaConsolidada, $indices[3300105]->ipf_quadrante);

        // (INA + (100 - IDAPS)) / 2: m1 = (100 + 87,5) / 2
        $this->assertEqualsWithDelta(93.75, $indices[3300101]->ipf_pontuacao, 0.001);
    }

    public function test_regra_de_efetividade_marca_estrutura_forte_com_resultado_fraco(): void
    {
        $this->calcular();

        $indices = $this->indicesDe(self::REFERENCIA);

        $this->assertTrue($indices[3300105]->estrutura_sem_resultado, 'm5: cobertura máxima e a pior ICSAP');
        $this->assertFalse($indices[3300104]->estrutura_sem_resultado);
        $this->assertFalse($indices[3300101]->estrutura_sem_resultado);
    }

    public function test_resultado_e_reproduzivel_e_recalcular_nao_duplica(): void
    {
        $this->calcular();
        $primeiro = IndiceMunicipio::query()->orderBy('municipio_id')->orderBy('competencia')->get(['municipio_id', 'competencia', 'ina', 'idaps', 'ipf_quadrante'])->toArray();

        $this->calcular();
        $segundo = IndiceMunicipio::query()->orderBy('municipio_id')->orderBy('competencia')->get(['municipio_id', 'competencia', 'ina', 'idaps', 'ipf_quadrante'])->toArray();

        $this->assertSame($primeiro, $segundo);
        $this->assertSame(15, IndiceMunicipio::count());
    }

    public function test_dado_novo_substitui_o_resultado_anterior(): void
    {
        $this->calcular();
        $antes = $this->indicesDe(self::REFERENCIA)[3300101]->idaps;

        $this->gravar($this->cobertura, [60, 20, 30, 40, 50], self::REFERENCIA);
        $this->calcular();

        $this->assertGreaterThan($antes, $this->indicesDe(self::REFERENCIA)[3300101]->idaps);
        $this->assertSame(15, IndiceMunicipio::count());
    }

    public function test_usa_o_ultimo_dado_conhecido_dentro_da_validade(): void
    {
        $this->calcular();

        $detalhe = $this->indicesDe(self::REFERENCIA)[3300101]->detalhes['ina']['componentes'];

        $this->assertSame(202212, $detalhe['pobreza']['competencia'], 'pobreza de 2022 vale em 2026 porque a validade configurada é de 120 meses');
        $this->assertSame(202512, $detalhe['renda']['competencia']);
    }

    public function test_valor_vencido_deixa_de_ser_usado(): void
    {
        // m1 só tem cobertura até abr/2026 (validade mensal = 2 meses): vale em mai e jun, vence em jul.
        ValorIndicador::query()->where('indicador_id', $this->cobertura->id)->where('municipio_id', 3300101)->whereIn('competencia', [202605, 202606, 202607])->delete();
        $this->gravar($this->cobertura, [10], 202604, [3300101]);

        $this->calcular();

        $this->assertNotNull($this->indicesDe(202606)[3300101]->idaps);
        $this->assertNull($this->indicesDe(self::REFERENCIA)[3300101]->idaps, 'sem estrutura vigente o pilar obrigatório falta');
        $this->assertNotNull($this->indicesDe(self::REFERENCIA)[3300101]->ina, 'o INA do mesmo município não é afetado');
        $this->assertNull($this->indicesDe(self::REFERENCIA)[3300101]->ipf_quadrante, 'sem os dois índices não há quadrante');
    }

    public function test_validade_padrao_por_periodicidade_vale_quando_o_indicador_nao_define_a_propria(): void
    {
        config(['indices.validade_meses.anual' => 12]);

        $this->calcular();

        $detalhe = $this->indicesDe(self::REFERENCIA)[3300101]->detalhes['ina'];

        $this->assertSame(202512, $detalhe['componentes']['renda']['competencia'], 'renda de dez/2025 tem 7 meses: ainda vale');
        $this->assertArrayHasKey('pobreza', $detalhe['componentes'], 'pobreza tem validade própria de 120 meses');

        config(['indices.validade_meses.anual' => 3]);
        $this->calcular();

        $detalhe = $this->indicesDe(self::REFERENCIA)[3300101]->detalhes['ina'];
        $this->assertArrayNotHasKey('renda', $detalhe['componentes'], 'com validade de 3 meses a renda de dez/2025 venceu em todos os meses do período');
        $this->assertSame([], $detalhe['faltantes'], 'sem dado válido em nenhum mês do período, o indicador sai da conta em vez de pesar contra o município');
    }

    public function test_indicador_que_tinha_dado_no_periodo_e_venceu_derruba_a_confianca(): void
    {
        // Renda de dez/2025 (annual) com validade de 5 meses: vale em mai/2026 (5 meses), vence em jun e jul.
        config(['indices.validade_meses.anual' => 5]);

        $this->calcular();

        $maio = $this->indicesDe(202605)[3300101];
        $julho = $this->indicesDe(self::REFERENCIA)[3300101];

        $this->assertEqualsWithDelta(1.0, $maio->confianca_ina, 0.001);
        $this->assertNotNull($maio->ina);
        $this->assertEqualsWithDelta(0.5, $julho->confianca_ina, 0.001, 'a renda existiu no período e agora falta: metade do pilar');
        $this->assertNull($julho->ina, 'abaixo da confiança mínima de 0,7 o índice não é calculado');
        $this->assertContains('renda', $julho->detalhes['ina']['faltantes']);
    }

    public function test_indicador_carregado_so_para_parte_dos_pares_e_ignorado_sem_penalizar_a_confianca(): void
    {
        $bolsa = $this->indicador('bolsa', Periodicidade::Mensal, Polaridade::MenorMelhor, Dimensao::Necessidade);
        $this->gravar($bolsa, [90, 10], 202607, [3300101, 3300102]);
        config(['indices.ina.pilares.beneficios' => ['peso' => 1, 'componentes' => ['bolsa' => ['peso' => 1, 'sentido' => 'alto']]]]);

        $this->calcular();

        $m1 = $this->indicesDe(self::REFERENCIA)[3300101];

        $this->assertEqualsWithDelta(100.0, $m1->ina, 0.001, 'o INA não mudou: 2 de 5 municípios (40%) não bastam para entrar');
        $this->assertEqualsWithDelta(1.0, $m1->confianca_ina, 0.001);
        $this->assertArrayNotHasKey('bolsa', $m1->detalhes['ina']['componentes']);
        $this->assertSame([], $m1->detalhes['ina']['faltantes']);
    }

    public function test_pilar_obrigatorio_sem_cobertura_entre_os_pares_impede_o_ina_mas_nao_o_idaps(): void
    {
        $bolsa = $this->indicador('bolsa', Periodicidade::Mensal, Polaridade::MenorMelhor, Dimensao::Necessidade);
        $this->gravar($bolsa, [90, 10], 202607, [3300101, 3300102]);
        config(['indices.ina.pilares' => ['vulnerabilidade' => ['peso' => 1, 'obrigatorio' => true, 'componentes' => ['bolsa' => ['peso' => 1, 'sentido' => 'alto']]]]]);

        $resultado = $this->calcular();

        $indices = $this->indicesDe(self::REFERENCIA);

        $this->assertSame(5, $indices->whereNull('ina')->count());
        $this->assertSame(5, $indices->whereNotNull('idaps')->count());
        $this->assertSame(0, $indices->whereNotNull('ipf_quadrante')->count());
        $this->assertSame([], $resultado['quadrantes']);
    }

    public function test_mes_sem_o_indicador_obrigatorio_gera_so_o_indice_que_tem_dados(): void
    {
        ValorIndicador::query()->where('indicador_id', $this->cobertura->id)->where('competencia', 202605)->delete();

        $this->calcular();

        $indices = $this->indicesDe(202605);

        $this->assertCount(5, $indices, 'o INA existe, então há linha');
        $this->assertSame(5, $indices->whereNull('idaps')->count(), 'sem cobertura em mai/2026 a estrutura falta e o IDAPS não é calculado');
        $this->assertSame(5, $indices->whereNotNull('ina')->count());
        $this->assertSame(0, $indices->whereNotNull('ipf_quadrante')->count());
    }

    public function test_so_os_municipios_do_mesmo_estado_sao_comparados(): void
    {
        $mg = [3100101, 3100102, 3100103, 3100104, 3100105];

        foreach ($mg as $id) {
            Municipio::factory()->create(['id' => $id, 'codigo6' => $id % 1000000, 'uf' => 'MG', 'codigo_uf' => 31]);
        }

        foreach ([202605, 202606, 202607] as $mes) {
            $this->gravar($this->cobertura, [1000, 2000, 3000, 4000, 5000], $mes, $mg);
            $this->gravar($this->icsap, [1, 2, 3, 4, 5], $mes, $mg);
        }

        $this->gravar($this->pobreza, [1, 2, 3, 4, 5], 202212, $mg);
        $this->gravar($this->renda, [5, 4, 3, 2, 1], 202512, $mg);

        $this->calcular();

        $rj = $this->indicesDe(self::REFERENCIA);

        $this->assertEqualsWithDelta(12.5, $rj[3300101]->idaps, 0.001, 'valores gigantes de MG não alteram as notas do RJ');
        $this->assertSame(15, IndiceMunicipio::query()->whereIn('municipio_id', $mg)->count(), '5 municípios de MG × 3 meses');
        $this->assertEqualsWithDelta(50.0, IndiceMunicipio::query()->where('municipio_id', 3100101)->where('competencia', self::REFERENCIA)->value('idaps'), 0.001, 'em MG cobertura e ICSAP crescem juntas: pior cobertura (nota 0) com a melhor ICSAP (nota 100) dá 50');
    }

    public function test_estado_com_menos_pares_que_o_minimo_nao_e_comparado_e_gera_aviso(): void
    {
        foreach ([3500101, 3500102, 3500103] as $id) {
            Municipio::factory()->create(['id' => $id, 'codigo6' => $id % 1000000, 'uf' => 'SP', 'codigo_uf' => 35]);
        }

        $resultado = $this->calcular();

        $this->assertSame(0, IndiceMunicipio::query()->whereIn('municipio_id', [3500101, 3500102, 3500103])->count());
        $this->assertStringContainsString('UF 35', implode(' ', $resultado['avisos']));
        $this->assertSame(15, $resultado['linhas'], 'o RJ continua sendo calculado');
    }

    public function test_sem_dados_da_ancora_nada_e_calculado_e_ha_aviso(): void
    {
        ValorIndicador::query()->where('indicador_id', $this->cobertura->id)->delete();

        $resultado = $this->calcular();

        $this->assertSame(0, $resultado['linhas']);
        $this->assertNull($resultado['ultima_competencia']);
        $this->assertStringContainsString('cobertura', implode(' ', $resultado['avisos']));
        $this->assertSame(0, IndiceMunicipio::count());
    }

    public function test_grava_as_series_calculadas_como_indicadores_com_benchmarks(): void
    {
        $this->calcular();

        $idaps = Indicador::where('codigo', 'indice_idaps')->firstOrFail();
        $prioridade = Indicador::where('codigo', 'indice_prioridade')->firstOrFail();

        $this->assertSame(15, ValorIndicador::where('indicador_id', $idaps->id)->count());
        $this->assertEqualsWithDelta(62.5, ValorIndicador::where('indicador_id', $idaps->id)->where('municipio_id', 3300103)->where('competencia', self::REFERENCIA)->value('valor'), 0.001);
        $this->assertSame(15, ValorIndicador::where('indicador_id', $prioridade->id)->count());

        $benchmark = Benchmark::where('indicador_id', $idaps->id)->where('competencia', self::REFERENCIA)->where('escopo', 2)->firstOrFail();
        $this->assertSame(5, $benchmark->quantidade);
        $this->assertEqualsWithDelta(50.0, $benchmark->mediana, 0.001);
    }

    public function test_recalcular_nao_deixa_resto_de_meses_que_deixaram_de_ser_calculaveis(): void
    {
        $this->calcular();

        ValorIndicador::query()->where('indicador_id', $this->cobertura->id)->where('competencia', 202607)->delete();
        $this->calcular();

        $idaps = Indicador::where('codigo', 'indice_idaps')->firstOrFail();

        $this->assertSame(202606, IndiceMunicipio::max('competencia'), 'a âncora recuou para jun/2026');
        $this->assertSame(0, ValorIndicador::where('indicador_id', $idaps->id)->where('competencia', 202607)->count());
        $this->assertSame(0, Benchmark::where('indicador_id', $idaps->id)->where('competencia', 202607)->count());
    }

    public function test_mudar_a_metodologia_gera_nova_versao_e_todas_as_linhas_passam_a_usa_la(): void
    {
        $this->calcular();
        $v1 = Metodologia::ativa()->firstOrFail();

        config(['indices.idaps.pilares.estrutura.peso' => 3]);
        $this->calcular();
        $v2 = Metodologia::ativa()->firstOrFail();

        $this->assertSame(2, $v2->versao);
        $this->assertSame([$v2->id], IndiceMunicipio::query()->distinct()->pluck('metodologia_id')->all());
        $this->assertSame(2, Metodologia::count());
        $this->assertNotSame($v1->id, $v2->id);

        // estrutura com peso 3 e resultado com peso 1: m1 = (3 × 0 + 1 × 25) / 4
        $this->assertEqualsWithDelta(6.25, $this->indicesDe(self::REFERENCIA)[3300101]->idaps, 0.001);
    }

    public function test_calcular_com_uma_versao_antiga_reproduz_o_resultado_dela(): void
    {
        $this->calcular();
        $v1 = Metodologia::ativa()->firstOrFail();
        $originais = $this->indicesDe(self::REFERENCIA)->pluck('idaps', 'municipio_id')->all();

        config(['indices.idaps.pilares.estrutura.peso' => 3]);
        $this->calcular();

        app(CalculadorDeIndices::class)->calcular($v1);

        $this->assertEquals($originais, $this->indicesDe(self::REFERENCIA)->pluck('idaps', 'municipio_id')->all());
        $this->assertSame([$v1->id], IndiceMunicipio::query()->distinct()->pluck('metodologia_id')->all());
    }

    public function test_afetado_por_so_responde_sim_para_indicadores_da_metodologia(): void
    {
        $fora = $this->indicador('populacao_total', Periodicidade::Anual, Polaridade::Neutra, Dimensao::Necessidade);
        $calculador = app(CalculadorDeIndices::class);

        $this->assertTrue($calculador->afetadoPor([$this->icsap->id]));
        $this->assertTrue($calculador->afetadoPor([$fora->id, $this->renda->id]));
        $this->assertFalse($calculador->afetadoPor([$fora->id]));
        $this->assertFalse($calculador->afetadoPor([]));
    }

    public function test_comando_calcula_e_mostra_a_versao_e_os_quadrantes(): void
    {
        $this->artisan('aps:calcular-indices')
            ->expectsOutputToContain('Metodologia versão 1')
            ->expectsOutputToContain('Prioridade máxima')
            ->assertSuccessful();

        $this->assertSame(15, IndiceMunicipio::count());
    }

    public function test_o_indice_calculado_aparece_no_visual_do_painel_com_valor_e_analise(): void
    {
        $this->calcular();

        $usuario = User::factory()->gestor()->create();
        $widget = PainelWidget::factory()->create(['user_id' => $usuario->id, 'tipo' => TipoDeVisual::Destaque, 'indicador' => 'indice_idaps']);

        Livewire::withoutLazyLoading();
        Livewire::actingAs($usuario)
            ->test(Visual::class, ['widgetId' => $widget->id, 'municipioId' => 3300103, 'meses' => 24])
            ->assertSee('62,5')
            ->assertSee('Mediana do estado')
            ->assertSee('Análise do cenário atual');
    }

    public function test_comando_sem_dados_termina_com_sucesso_e_explica(): void
    {
        ValorIndicador::query()->delete();

        $this->artisan('aps:calcular-indices')
            ->expectsOutputToContain('Nenhum índice pôde ser calculado')
            ->assertSuccessful();
    }
}
