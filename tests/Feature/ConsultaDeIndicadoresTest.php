<?php

namespace Tests\Feature;

use App\Domain\Indicators\ConsultaDeIndicadores;
use App\Enums\EscopoBenchmark;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CriaCenarioDoPainel;
use Tests\TestCase;

class ConsultaDeIndicadoresTest extends TestCase
{
    use CriaCenarioDoPainel;
    use RefreshDatabase;

    private ConsultaDeIndicadores $consulta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->criarCenarioDoPainel();
        $this->consulta = app(ConsultaDeIndicadores::class);
    }

    public function test_ultimo_valor_e_a_competencia_mais_recente_do_municipio(): void
    {
        $this->assertSame(['competencia' => 202607, 'valor' => 88.0], $this->consulta->ultimo($this->valenca->id, $this->coberturaEsf));
        $this->assertNull($this->consulta->ultimo($this->caboFrio->id, $this->icsap));
    }

    public function test_um_ano_antes_encontra_a_competencia_do_ano_anterior(): void
    {
        $this->assertSame(['competencia' => 202507, 'valor' => 80.0], $this->consulta->umAnoAntes($this->valenca->id, $this->coberturaEsf, 202607));
        $this->assertNull($this->consulta->umAnoAntes($this->valenca->id, $this->coberturaEsf, 202601), 'não há dado entre 202401 e 202501');
        $this->assertSame(['competencia' => 202412, 'valor' => 71462.0], $this->consulta->umAnoAntes($this->valenca->id, $this->populacao, 202512));
    }

    public function test_serie_mensal_respeita_a_janela_a_partir_do_ultimo_dado(): void
    {
        $serie = $this->consulta->serie($this->valenca->id, $this->coberturaEsf, 3);

        $this->assertSame([202605, 202606, 202607], array_column($serie, 'competencia'));
        $this->assertSame([85.0, 86.0, 88.0], array_column($serie, 'valor'));

        $this->assertCount(8, $this->consulta->serie($this->valenca->id, $this->coberturaEsf, 36));
        $this->assertSame([], $this->consulta->serie($this->caboFrio->id, $this->icsap, 12));
    }

    public function test_serie_de_indicador_anual_mostra_pelo_menos_cinco_anos_mesmo_com_janela_curta(): void
    {
        $serie = $this->consulta->serie($this->valenca->id, $this->populacao, 12);

        $this->assertSame([202212, 202412, 202512], array_column($serie, 'competencia'));
    }

    public function test_medianas_por_escopo_e_competencia(): void
    {
        $regiao = $this->consulta->medianas($this->coberturaEsf, EscopoBenchmark::RegiaoSaude, 33004, [202507, 202607, 202601]);
        $estado = $this->consulta->medianas($this->coberturaEsf, EscopoBenchmark::Uf, 33, [202607]);

        $this->assertSame(75.0, $regiao[202607]);
        $this->assertSame(74.0, $regiao[202507]);
        $this->assertArrayNotHasKey(202601, $regiao, 'só Valença tem dado em jan/2026: nada a comparar');
        $this->assertSame(72.5, $estado[202607]);
        $this->assertSame([], $this->consulta->medianas($this->coberturaEsf, EscopoBenchmark::Uf, 33, []));
    }

    public function test_ranking_considera_so_a_regiao_e_ordena_pela_polaridade(): void
    {
        $maiorMelhor = $this->consulta->ranking($this->coberturaEsf, 202607, 33004);

        $this->assertSame(['Valença', 'Volta Redonda', 'Resende'], array_column($maiorMelhor, 'nome'));
        $this->assertNotContains('Cabo Frio', array_column($maiorMelhor, 'nome'));

        $this->gravar($this->icsap, $this->resende, [202607 => 20.0]);
        $this->gravar($this->icsap, $this->voltaRedonda, [202607 => 40.0]);

        $menorMelhor = $this->consulta->ranking($this->icsap, 202607, 33004);

        $this->assertSame(['Resende', 'Valença', 'Volta Redonda'], array_column($menorMelhor, 'nome'));
    }

    public function test_posicao_no_estado_conta_os_melhores_e_trata_empates(): void
    {
        $this->assertSame(['posicao' => 1, 'total' => 4], $this->consulta->posicaoNoEstado($this->coberturaEsf, 202607, $this->valenca, 88.0));
        $this->assertSame(['posicao' => 4, 'total' => 4], $this->consulta->posicaoNoEstado($this->coberturaEsf, 202607, $this->caboFrio, 60.0));

        $this->gravar($this->coberturaEsf, $this->resende, [202607 => 75.0]);

        $this->assertSame(['posicao' => 2, 'total' => 4], $this->consulta->posicaoNoEstado($this->coberturaEsf, 202607, $this->voltaRedonda, 75.0));
        $this->assertSame(['posicao' => 2, 'total' => 4], $this->consulta->posicaoNoEstado($this->coberturaEsf, 202607, $this->resende, 75.0), 'empatados dividem a mesma posição');
    }

    public function test_com_teto_valores_acima_dele_empatam_na_posicao_e_no_ranking_vale_o_valor_real_dentro_do_empate(): void
    {
        $this->coberturaEsf->update(['teto' => 100.0]);
        $this->gravar($this->coberturaEsf, $this->valenca, [202607 => 112.7]);
        $this->gravar($this->coberturaEsf, $this->resende, [202607 => 180.6]);
        $this->gravar($this->coberturaEsf, $this->voltaRedonda, [202607 => 90.0]);
        $this->gravar($this->coberturaEsf, $this->caboFrio, [202607 => 126.0]);
        $indicador = $this->coberturaEsf->fresh();

        $this->assertSame(['Cabo Frio'], array_column($this->consulta->ranking($indicador, 202607, 33001), 'nome'), 'Cabo Frio é a única da sua região');
        $this->assertSame(['Resende', 'Valença', 'Volta Redonda'], array_column($this->consulta->ranking($indicador, 202607, 33004), 'nome'));

        foreach ([[$this->valenca, 112.7], [$this->resende, 180.6], [$this->caboFrio, 126.0]] as [$municipio, $valor]) {
            $this->assertSame(['posicao' => 1, 'total' => 4], $this->consulta->posicaoNoEstado($indicador, 202607, $municipio, $valor), "{$municipio->nome} está no patamar pleno: empata em 1º");
        }

        $this->assertSame(['posicao' => 4, 'total' => 4], $this->consulta->posicaoNoEstado($indicador, 202607, $this->voltaRedonda, 90.0));
    }

    public function test_indicador_neutro_nao_tem_posicao(): void
    {
        $this->assertNull($this->consulta->posicaoNoEstado($this->populacao, 202512, $this->valenca, 71449.0));
    }

    public function test_benchmark_de_competencia_inexistente_e_nulo(): void
    {
        $this->assertNull($this->consulta->benchmark($this->coberturaEsf, 209901, EscopoBenchmark::Uf, 33));
        $this->assertSame(4, $this->consulta->benchmark($this->coberturaEsf, 202607, EscopoBenchmark::Uf, 33)->quantidade);
    }

    public function test_ultimos_valores_traz_o_valor_mais_recente_de_cada_municipio_e_indicador_em_uma_consulta(): void
    {
        $ultimos = $this->consulta->ultimosValores(
            [$this->valenca->id, $this->resende->id, $this->caboFrio->id],
            [$this->coberturaEsf->id, $this->icsap->id, $this->populacao->id],
        );

        $this->assertSame(['competencia' => 202607, 'valor' => 88.0], $ultimos[$this->valenca->id][$this->coberturaEsf->id]);
        $this->assertSame(['competencia' => 202607, 'valor' => 30.0], $ultimos[$this->valenca->id][$this->icsap->id]);
        $this->assertSame(['competencia' => 202512, 'valor' => 71449.0], $ultimos[$this->valenca->id][$this->populacao->id], 'o valor mais recente, não o de 2022');
        $this->assertSame(70.0, $ultimos[$this->resende->id][$this->coberturaEsf->id]['valor']);
        $this->assertArrayNotHasKey($this->icsap->id, $ultimos[$this->resende->id] ?? [], 'sem dado não vira zero');
        $this->assertArrayNotHasKey($this->voltaRedonda->id, $ultimos, 'município fora da lista não aparece');
    }

    public function test_ultimos_valores_sem_municipios_ou_sem_indicadores_devolve_vazio(): void
    {
        $this->assertSame([], $this->consulta->ultimosValores([], [$this->icsap->id]));
        $this->assertSame([], $this->consulta->ultimosValores([$this->valenca->id], []));
    }

    public function test_competencia_do_mapa_e_a_mais_recente_com_dado_para_ao_menos_metade_dos_municipios(): void
    {
        // Cobertura: 4 municípios em jul/2026. Em ago/2026, só 1 de 4: não serve.
        $this->gravar($this->coberturaEsf, $this->valenca, [202608 => 90.0]);

        $this->assertSame(202607, $this->consulta->competenciaDoMapa($this->coberturaEsf, 33));

        $this->gravar($this->coberturaEsf, $this->resende, [202608 => 70.0]);

        $this->assertSame(202608, $this->consulta->competenciaDoMapa($this->coberturaEsf, 33), '2 de 4 municípios já são metade');
    }

    public function test_competencia_do_mapa_sem_metade_dos_municipios_cai_na_mais_recente_com_algum_dado(): void
    {
        $this->assertSame(202607, $this->consulta->competenciaDoMapa($this->icsap, 33), 'só Valença tem ICSAP');
        $this->assertSame(202512, $this->consulta->competenciaDoMapa($this->populacao, 33));
    }

    public function test_competencia_do_mapa_sem_nenhum_dado_e_nula(): void
    {
        $this->assertNull($this->consulta->competenciaDoMapa($this->coberturaEsf, 35));
    }

    public function test_valores_da_competencia_traz_so_os_municipios_da_uf_naquele_mes(): void
    {
        $valores = $this->consulta->valoresDaCompetencia($this->coberturaEsf, 202607, 33);

        $this->assertEquals([
            $this->valenca->id => 88.0,
            $this->resende->id => 70.0,
            $this->voltaRedonda->id => 75.0,
            $this->caboFrio->id => 60.0,
        ], $valores);
        $this->assertSame([], $this->consulta->valoresDaCompetencia($this->coberturaEsf, 202607, 35));
        $this->assertSame([], $this->consulta->valoresDaCompetencia($this->coberturaEsf, 209901, 33));
    }

    public function test_serie_nao_se_perde_quando_hoje_e_dia_31_e_o_mes_do_dado_tem_menos_dias(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31 10:00'));

        try {
            $this->gravar($this->icsap, $this->resende, [202512 => 10.0, 202601 => 11.0, 202602 => 12.0]);

            $this->assertSame([202512, 202601, 202602], array_column($this->consulta->serie($this->resende->id, $this->icsap, 3), 'competencia'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
