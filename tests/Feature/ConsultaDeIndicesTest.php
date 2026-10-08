<?php

namespace Tests\Feature;

use App\Domain\Scoring\ConsultaDeIndices;
use App\Enums\QuadranteIpf;
use App\Models\IndiceMunicipio;
use App\Models\Municipio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CriaCenarioDeIndices;
use Tests\TestCase;

class ConsultaDeIndicesTest extends TestCase
{
    use CriaCenarioDeIndices;
    use RefreshDatabase;

    private ConsultaDeIndices $consulta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->criarCenarioDeIndices();
        $this->consulta = app(ConsultaDeIndices::class);
    }

    public function test_competencias_vem_do_mais_recente_para_o_mais_antigo_e_so_da_uf_pedida(): void
    {
        $this->assertSame([202607, 202606], $this->consulta->competencias(33));
        $this->assertSame([], $this->consulta->competencias(35));
    }

    public function test_do_mes_traz_so_a_uf_e_o_mes_pedidos_indexados_pelo_municipio_com_o_nome_carregado(): void
    {
        $outraUf = Municipio::factory()->create(['id' => 3550308, 'codigo6' => 355030, 'uf' => 'SP', 'codigo_uf' => 35]);
        IndiceMunicipio::factory()->create(['municipio_id' => $outraUf->id, 'competencia' => 202607, 'metodologia_id' => $this->metodologia->id]);

        $linhas = $this->consulta->doMes(33, 202607);

        $this->assertCount(6, $linhas);
        $this->assertTrue($linhas->has(3306107));
        $this->assertFalse($linhas->has(3550308));
        $this->assertSame('Valença', $linhas[3306107]->municipio->nome);
        $this->assertSame(202607, $linhas[3306107]->competencia);
    }

    public function test_do_mes_ignora_municipios_inativos(): void
    {
        Municipio::whereKey(3306107)->update(['ativo' => false]);

        $this->assertFalse($this->consulta->doMes(33, 202607)->has(3306107));
    }

    public function test_cortes_sao_a_mediana_dos_municipios_com_os_dois_indices(): void
    {
        $cortes = $this->consulta->cortes($this->consulta->doMes(33, 202607));

        $this->assertEqualsWithDelta(55.0, $cortes['ina'], 0.001);
        $this->assertEqualsWithDelta(50.0, $cortes['idaps'], 0.001);
    }

    public function test_cortes_ignoram_quem_tem_so_um_dos_indices_e_sao_nulos_sem_ninguem_completo(): void
    {
        IndiceMunicipio::query()->where('municipio_id', 3302403)->update(['ina' => null]);

        $this->assertEqualsWithDelta(40.0, $this->consulta->cortes($this->consulta->doMes(33, 202607))['ina'], 0.001, 'mediana de 80, 40, 70, 30 e 20 (sem o INA de Macaé)');

        IndiceMunicipio::query()->update(['ina' => null]);

        $this->assertNull($this->consulta->cortes($this->consulta->doMes(33, 202607)));
    }

    public function test_cortes_respeitam_o_valor_fixo_da_metodologia_ativa(): void
    {
        $config = $this->metodologia->configuracao;
        $config['ipf']['corte'] = 40;
        $this->metodologia->update(['configuracao' => $config]);

        $cortes = $this->consulta->cortes($this->consulta->doMes(33, 202607));

        $this->assertSame(40.0, $cortes['ina']);
        $this->assertSame(40.0, $cortes['idaps']);
    }

    public function test_resumo_conta_municipios_por_quadrante_incluindo_os_vazios(): void
    {
        $resumo = $this->consulta->resumoDosQuadrantes($this->consulta->doMes(33, 202607));

        $this->assertSame([
            QuadranteIpf::PrioridadeMaxima->value => 2,
            QuadranteIpf::GrandePotencial->value => 1,
            QuadranteIpf::OportunidadeModerada->value => 1,
            QuadranteIpf::EstruturaConsolidada->value => 2,
        ], $resumo);

        $this->assertSame(array_fill_keys([1, 2, 3, 4], 0), $this->consulta->resumoDosQuadrantes(collect()));
    }

    public function test_por_prioridade_ordena_da_maior_pontuacao_para_a_menor_e_desempata_pelo_nome(): void
    {
        IndiceMunicipio::query()->where('municipio_id', 3303302)->update(['ipf_pontuacao' => 25.0]);

        $nomes = $this->consulta->porPrioridade($this->consulta->doMes(33, 202607))->map(fn (IndiceMunicipio $linha): string => $linha->municipio->nome)->all();

        $this->assertSame(['Macaé', 'Valença', 'Volta Redonda', 'Resende', 'Cabo Frio', 'Niterói'], $nomes);
    }

    public function test_por_prioridade_deixa_de_fora_quem_nao_tem_pontuacao(): void
    {
        IndiceMunicipio::query()->where('municipio_id', 3302403)->update(['ipf_pontuacao' => null]);

        $this->assertCount(5, $this->consulta->porPrioridade($this->consulta->doMes(33, 202607)));
    }

    public function test_serie_do_municipio_vai_do_mes_mais_antigo_ao_mais_recente(): void
    {
        $serie = $this->consulta->serie(3306107);

        $this->assertSame([202606, 202607], $serie->pluck('competencia')->all());
        $this->assertSame([25.0, 30.0], $serie->pluck('idaps')->all());
    }
}
