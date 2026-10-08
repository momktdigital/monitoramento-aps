<?php

namespace Tests\Feature;

use App\Domain\Indicators\CalculadorDeBenchmarks;
use App\Domain\Narrative\Analise;
use App\Enums\TipoDeVisual;
use App\Support\VersaoDosDados;
use App\Widgets\ConstrutorDeVisual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CriaCenarioDoPainel;
use Tests\TestCase;

class ConstrutorDeVisualTest extends TestCase
{
    use CriaCenarioDoPainel;
    use RefreshDatabase;

    private ConstrutorDeVisual $construtor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->criarCenarioDoPainel();
        $this->construtor = app(ConstrutorDeVisual::class);
    }

    public function test_destaque_traz_numero_variacao_comparativos_sparkline_e_analise(): void
    {
        $visual = $this->construtor->construir(TipoDeVisual::Destaque, $this->coberturaEsf, $this->valenca, 24);

        $this->assertSame('Cobertura da Estratégia Saúde da Família', $visual['titulo']);
        $this->assertSame('jul/2026', $visual['competencia']);
        $this->assertFalse($visual['sem_dado']);
        $this->assertSame('88,0%', $visual['kpi']['numero']);
        $this->assertSame('', $visual['kpi']['unidade']);

        $variacao = $visual['kpi']['variacao'];
        $this->assertSame('+8,0 p.p.', $variacao['texto']);
        $this->assertSame(1, $variacao['direcao']);
        $this->assertSame(1, $variacao['sentido']);
        $this->assertSame('jul/2025', $variacao['referencia']);

        $this->assertSame(
            [['rotulo' => 'Mediana da região de saúde', 'valor' => '75,0%'], ['rotulo' => 'Mediana do estado', 'valor' => '72,5%']],
            $visual['kpi']['comparativos'],
        );
        $this->assertSame([83.0, 84.0, 85.0, 86.0, 88.0], array_slice($visual['kpi']['sparkline'], -5));
        $this->assertSame(Analise::FAVORAVEL, $visual['analise']['tom']);
        $this->assertSame($this->coberturaEsf->o_que_e, $visual['indicador']['o_que_e']);
        $this->assertSame('e-Gestor APS (Ministério da Saúde)', $visual['indicador']['fonte']);
    }

    public function test_cobertura_acima_de_100_por_cento_e_apresentada_como_patamar_pleno_e_nao_como_atraso(): void
    {
        $this->coberturaEsf->update(['teto' => 100.0]);
        $this->gravar($this->coberturaEsf, $this->valenca, [202607 => 112.7]);
        $this->gravar($this->coberturaEsf, $this->resende, [202607 => 150.0]);
        $this->gravar($this->coberturaEsf, $this->voltaRedonda, [202607 => 126.0]);
        app(CalculadorDeBenchmarks::class)->recalcularTudo();
        VersaoDosDados::renovar();
        $indicador = $this->coberturaEsf->fresh();

        $destaque = $this->construtor->construir(TipoDeVisual::Destaque, $indicador, $this->valenca, 24);
        $texto = implode(' ', $destaque['analise']['paragrafos']);

        $this->assertSame(Analise::FAVORAVEL, $destaque['analise']['tom']);
        $this->assertStringContainsString('patamar pleno', $texto);
        $this->assertStringNotContainsString('merece atenção', $texto);
        $this->assertStringContainsString('ocupa a 1ª posição', $texto);

        $ranking = $this->construtor->construir(TipoDeVisual::Ranking, $indicador, $this->valenca, 24);

        $this->assertSame(['Resende', 'Volta Redonda', 'Valença'], $ranking['grafico']['categorias'], 'dentro do empate no teto, o gráfico mostra o valor real, do maior ao menor');
        $this->assertStringContainsString('Todos os 3 municípios estão no patamar pleno', implode(' ', $ranking['analise']['paragrafos']));
        $this->assertSame(Analise::FAVORAVEL, $ranking['analise']['tom']);
    }

    public function test_destaque_de_indicador_com_unidade_textual_separa_numero_e_unidade(): void
    {
        $visual = $this->construtor->construir(TipoDeVisual::Destaque, $this->populacao, $this->valenca, 24);

        $this->assertSame('71.449', $visual['kpi']['numero']);
        $this->assertSame('pessoas', $visual['kpi']['unidade']);
        $this->assertSame('2025', $visual['competencia']);
        $this->assertSame(Analise::INFORMATIVO, $visual['analise']['tom']);
    }

    public function test_evolucao_monta_series_do_municipio_da_regiao_e_do_estado_e_a_tabela(): void
    {
        $visual = $this->construtor->construir(TipoDeVisual::Evolucao, $this->coberturaEsf, $this->valenca, 24);
        $grafico = $visual['grafico'];

        $this->assertSame('Evolução: Cobertura da Estratégia Saúde da Família', $visual['titulo']);
        $this->assertSame('evolucao', $grafico['modo']);
        $this->assertSame(['jul/2025', 'jan/2026', 'fev/2026', 'mar/2026', 'abr/2026', 'mai/2026', 'jun/2026', 'jul/2026'], $grafico['categorias']);
        $this->assertSame(['municipio', 'regiao', 'estado'], array_column($grafico['series'], 'papel'));
        $this->assertSame(['Valença', 'Mediana da região de saúde', 'Mediana do estado'], array_column($grafico['series'], 'nome'));
        $this->assertSame(88.0, $grafico['series'][0]['dados'][7]);
        $this->assertSame(75.0, $grafico['series'][1]['dados'][7]);
        $this->assertNull($grafico['series'][1]['dados'][1], 'competência sem benchmark vira lacuna, não zero');
        $this->assertSame(['%', 1, ''], [trim($grafico['sufixo']), $grafico['casas'], $grafico['prefixo']]);

        $this->assertSame(['Período', 'Valença', 'Mediana da região de saúde', 'Mediana do estado'], $visual['tabela']['colunas']);
        $this->assertSame(['jul/2026', '88,0%', '75,0%', '72,5%'], $visual['tabela']['linhas'][7]);
        $this->assertSame('—', $visual['tabela']['linhas'][1][2]);
    }

    public function test_evolucao_respeita_a_janela_de_meses(): void
    {
        $visual = $this->construtor->construir(TipoDeVisual::Evolucao, $this->coberturaEsf, $this->valenca, 3);

        $this->assertSame(['mai/2026', 'jun/2026', 'jul/2026'], $visual['grafico']['categorias']);
    }

    public function test_ranking_destaca_o_municipio_e_informa_a_mediana(): void
    {
        $visual = $this->construtor->construir(TipoDeVisual::Ranking, $this->coberturaEsf, $this->resende, 24);
        $grafico = $visual['grafico'];

        $this->assertSame('ranking', $grafico['modo']);
        $this->assertSame(['Valença', 'Volta Redonda', 'Resende'], $grafico['categorias']);
        $this->assertSame([88.0, 75.0, 70.0], $grafico['valores']);
        $this->assertSame(2, $grafico['destaque']);
        $this->assertSame(75.0, $grafico['mediana']);
        $this->assertSame(['Posição', 'Município', 'Valor'], $visual['tabela']['colunas']);
        $this->assertSame(['3ª', 'Resende', '70,0%'], $visual['tabela']['linhas'][2]);
        $this->assertSame(Analise::ATENCAO, $visual['analise']['tom']);
    }

    public function test_sem_dados_retorna_estado_vazio_com_explicacao_para_todos_os_tipos(): void
    {
        foreach (TipoDeVisual::cases() as $tipo) {
            $visual = $this->construtor->construir($tipo, $this->icsap, $this->caboFrio, 24);

            $this->assertTrue($visual['sem_dado'], $tipo->value);
            $this->assertSame(Analise::SEM_DADO, $visual['analise']['tom']);
            $this->assertNull($visual['kpi']);
            $this->assertNull($visual['grafico']);
            $this->assertStringContainsString('SIH/SUS', $visual['analise']['paragrafos'][1]);
        }
    }

    public function test_municipio_sem_regiao_de_saude_ainda_mostra_destaque_e_evolucao(): void
    {
        $this->valenca->update(['regiao_saude_codigo' => null]);

        $destaque = $this->construtor->construir(TipoDeVisual::Destaque, $this->coberturaEsf, $this->valenca->fresh(), 24);
        $evolucao = $this->construtor->construir(TipoDeVisual::Evolucao, $this->coberturaEsf, $this->valenca->fresh(), 24);
        $ranking = $this->construtor->construir(TipoDeVisual::Ranking, $this->coberturaEsf, $this->valenca->fresh(), 24);

        $this->assertFalse($destaque['sem_dado']);
        $this->assertSame(['Mediana do estado'], array_column($destaque['kpi']['comparativos'], 'rotulo'));
        $this->assertSame(['municipio', 'estado'], array_column($evolucao['grafico']['series'], 'papel'));
        $this->assertTrue($ranking['sem_dado']);
    }

    public function test_o_resultado_e_cacheado_e_renovado_quando_chegam_dados_novos(): void
    {
        $primeira = $this->construtor->construir(TipoDeVisual::Destaque, $this->coberturaEsf, $this->valenca, 24);

        $consultas = 0;
        DB::listen(function () use (&$consultas): void {
            $consultas++;
        });

        $this->construtor->construir(TipoDeVisual::Destaque, $this->coberturaEsf, $this->valenca, 24);
        $this->assertSame(0, $consultas, 'a segunda leitura vem do cache, sem tocar no banco');

        $this->gravar($this->coberturaEsf, $this->valenca, [202608 => 90.0]);

        $this->assertSame($primeira['kpi']['numero'], $this->construtor->construir(TipoDeVisual::Destaque, $this->coberturaEsf, $this->valenca, 24)['kpi']['numero'], 'sem renovar a versão, o cache continua valendo');

        VersaoDosDados::renovar();

        $this->assertSame('90,0%', $this->construtor->construir(TipoDeVisual::Destaque, $this->coberturaEsf, $this->valenca, 24)['kpi']['numero']);
    }

    public function test_cache_e_separado_por_municipio_e_por_janela(): void
    {
        $this->assertNotSame(
            $this->construtor->construir(TipoDeVisual::Evolucao, $this->coberturaEsf, $this->valenca, 3)['grafico']['categorias'],
            $this->construtor->construir(TipoDeVisual::Evolucao, $this->coberturaEsf, $this->valenca, 24)['grafico']['categorias'],
        );

        $this->assertSame('70,0%', $this->construtor->construir(TipoDeVisual::Destaque, $this->coberturaEsf, $this->resende, 24)['kpi']['numero']);
        $this->assertSame('88,0%', $this->construtor->construir(TipoDeVisual::Destaque, $this->coberturaEsf, $this->valenca, 24)['kpi']['numero']);
    }
}
