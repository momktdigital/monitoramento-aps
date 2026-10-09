<?php

namespace Tests\Feature;

use App\Domain\Indicators\CalculadorDeBenchmarks;
use App\Domain\Narrative\Analise;
use App\Enums\TipoDeVisual;
use App\Models\Indicador;
use App\Models\Municipio;
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
        $this->assertSame([83.0, 84.0, 85.0, 86.0, 88.0], array_slice(array_column($visual['kpi']['sparkline'], 'valor'), -5));
        $this->assertSame(['rotulo' => 'jul/2026', 'texto' => '88,0%', 'valor' => 88.0], last($visual['kpi']['sparkline']), 'cada ponto do sparkline traz o período e o valor escrito, para a dica');
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

    public function test_destaque_traz_a_faixa_de_posicao_na_regiao_com_menor_maior_mediana_e_posicao(): void
    {
        $faixa = $this->construtor->construir(TipoDeVisual::Destaque, $this->coberturaEsf, $this->valenca, 24)['kpi']['faixa'];

        // Região 33004 em jul/2026: Valença 88, Volta Redonda 75, Resende 70.
        $this->assertSame(['70,0%', '88,0%', '75,0%', '88,0%'], [$faixa['minimo'], $faixa['maximo'], $faixa['mediana'], $faixa['valor']]);
        $this->assertSame(100.0, $faixa['x_valor']);
        $this->assertEqualsWithDelta(27.8, $faixa['x_mediana'], 0.1);
        $this->assertSame([1, 3], [$faixa['posicao'], $faixa['total']]);
        $this->assertTrue($faixa['melhor_e_maior']);
        $this->assertFalse($faixa['melhor_e_menor']);
    }

    public function test_faixa_de_posicao_nao_existe_com_poucos_municipios_na_regiao_nem_sem_regiao(): void
    {
        $this->assertNull($this->construtor->construir(TipoDeVisual::Destaque, $this->populacao, $this->valenca, 24)['kpi']['faixa'], 'população só tem Valença: sem região para comparar');

        $this->valenca->update(['regiao_saude_codigo' => null]);

        $this->assertNull($this->construtor->construir(TipoDeVisual::Destaque, $this->coberturaEsf, $this->valenca->fresh(), 24)['kpi']['faixa']);
    }

    public function test_faixa_de_indicador_neutro_nao_tem_posicao(): void
    {
        $this->gravar($this->populacao, $this->resende, [202512 => 130000.0]);
        $this->gravar($this->populacao, $this->voltaRedonda, [202512 => 270000.0]);
        app(CalculadorDeBenchmarks::class)->recalcularTudo();
        VersaoDosDados::renovar();

        $faixa = $this->construtor->construir(TipoDeVisual::Destaque, $this->populacao, $this->valenca, 24)['kpi']['faixa'];

        $this->assertNull($faixa['posicao'], 'sem sentido bom ou ruim, ninguém está "à frente"');
        $this->assertFalse($faixa['melhor_e_maior']);
        $this->assertFalse($faixa['melhor_e_menor']);
    }

    public function test_evolucao_informa_o_patamar_pleno_quando_o_indicador_tem_teto(): void
    {
        $this->coberturaEsf->update(['teto' => 100.0]);
        VersaoDosDados::renovar();

        $grafico = $this->construtor->construir(TipoDeVisual::Evolucao, $this->coberturaEsf->fresh(), $this->valenca, 24)['grafico'];

        $this->assertSame(100.0, $grafico['teto']);
        $this->assertSame('Cobertura da Estratégia Saúde da Família', $grafico['indicador']);
    }

    public function test_ranking_traz_ids_posicoes_e_total_para_o_clique_e_a_dica(): void
    {
        $grafico = $this->construtor->construir(TipoDeVisual::Ranking, $this->coberturaEsf, $this->resende, 24)['grafico'];

        $this->assertSame([$this->valenca->id, $this->voltaRedonda->id, $this->resende->id], $grafico['ids']);
        $this->assertSame([1, 2, 3], $grafico['posicoes']);
        $this->assertSame(3, $grafico['total']);
        $this->assertSame('Mediana da região', $grafico['rotulo_da_mediana']);
        $this->assertTrue($grafico['melhor_e_maior']);
    }

    public function test_ranking_do_estado_mostra_todos_os_municipios_quando_sao_poucos_e_a_mediana_do_estado(): void
    {
        $visual = $this->construtor->construir(TipoDeVisual::Ranking, $this->coberturaEsf, $this->resende, 24, ConstrutorDeVisual::ESCOPO_ESTADO);
        $grafico = $visual['grafico'];

        $this->assertSame('estado', $visual['escopo']);
        $this->assertSame(['Valença', 'Volta Redonda', 'Resende', 'Cabo Frio'], $grafico['categorias']);
        $this->assertSame(4, $grafico['total']);
        $this->assertSame('Mediana do estado', $grafico['rotulo_da_mediana']);
        $this->assertSame(72.5, $grafico['mediana']);
        $this->assertSame(2, $grafico['destaque']);
        $this->assertStringContainsString('No estado, Resende ocupa a 3ª posição entre 4 municípios', implode(' ', $visual['analise']['paragrafos']));
        $this->assertSame(['3ª', 'Resende', '70,0%'], $visual['tabela']['linhas'][2]);
    }

    public function test_ranking_do_estado_com_muitos_municipios_mostra_uma_janela_em_volta_do_municipio_com_as_posicoes_reais(): void
    {
        $outros = [];

        for ($i = 1; $i <= 30; $i++) {
            $id = 3500000 + $i * 7;
            $outros[$i] = Municipio::factory()->create(['id' => $id, 'codigo6' => $id % 1000000, 'regiao_saude_codigo' => 35001, 'nome' => sprintf('Cidade %02d', $i)]);
            $this->gravar($this->coberturaEsf, $outros[$i], [202607 => 100.0 - $i]);
        }

        app(CalculadorDeBenchmarks::class)->recalcularTudo();
        VersaoDosDados::renovar();

        // Valores 99, 98, ..., 70 para as cidades 1 a 30; o cenário tem Valença (88), Volta Redonda (75), Resende (70) e Cabo Frio (60).
        $meio = $this->construtor->construir(TipoDeVisual::Ranking, $this->coberturaEsf, $outros[20], 24, ConstrutorDeVisual::ESCOPO_ESTADO)['grafico'];

        $this->assertCount(15, $meio['categorias'], '7 acima, o município e 7 abaixo');
        $this->assertSame('Cidade 20', $meio['categorias'][$meio['destaque']]);
        $this->assertSame(7, $meio['destaque']);
        $this->assertSame(34, $meio['total']);
        $this->assertSame(range($meio['posicoes'][0], $meio['posicoes'][0] + 14), $meio['posicoes'], 'posições seguidas, contadas no estado inteiro');
        $this->assertGreaterThan(15, $meio['posicoes'][7], 'a Cidade 20 (valor 80) está bem abaixo do topo do estado');

        $topo = $this->construtor->construir(TipoDeVisual::Ranking, $this->coberturaEsf, $outros[1], 24, ConstrutorDeVisual::ESCOPO_ESTADO)['grafico'];

        $this->assertSame(1, $topo['posicoes'][0]);
        $this->assertSame(0, $topo['destaque']);
        $this->assertCount(15, $topo['categorias'], 'nas pontas a janela encosta no limite em vez de encolher');
    }

    public function test_o_escopo_faz_parte_do_cache_do_visual_e_so_vale_para_o_ranking(): void
    {
        $regiao = $this->construtor->construir(TipoDeVisual::Ranking, $this->coberturaEsf, $this->resende, 24);
        $estado = $this->construtor->construir(TipoDeVisual::Ranking, $this->coberturaEsf, $this->resende, 24, ConstrutorDeVisual::ESCOPO_ESTADO);

        $this->assertSame('regiao', $regiao['escopo']);
        $this->assertSame('estado', $estado['escopo']);
        $this->assertNotSame($regiao['grafico']['categorias'], $estado['grafico']['categorias']);
        $this->assertNull($this->construtor->construir(TipoDeVisual::Destaque, $this->coberturaEsf, $this->resende, 24, ConstrutorDeVisual::ESCOPO_ESTADO)['escopo']);
    }

    public function test_escopo_desconhecido_vira_regiao(): void
    {
        $visual = $this->construtor->construir(TipoDeVisual::Ranking, $this->coberturaEsf, $this->resende, 24, 'galaxia');

        $this->assertSame('regiao', $visual['escopo']);
    }

    public function test_mapa_traz_regioes_faixas_legenda_tabela_e_analise(): void
    {
        $visual = $this->construtor->construir(TipoDeVisual::Mapa, $this->coberturaEsf, $this->valenca, 24);
        $mapa = $visual['grafico'];

        $this->assertSame('Mapa: Cobertura da Estratégia Saúde da Família', $visual['titulo']);
        $this->assertSame('jul/2026', $visual['competencia']);
        $this->assertSame('mapa', $mapa['modo']);
        $this->assertSame('uf-33', $mapa['mapa']);
        $this->assertSame('/dados/malha/33', $mapa['malha'], 'o endereço é relativo: serve em qualquer domínio');
        $this->assertCount(4, $mapa['regioes']);
        $this->assertSame((string) $this->valenca->id, $mapa['selecionado']);
        $this->assertSame('faixas', $visual['legenda']['tipo']);
        $this->assertSame($mapa['faixas'], $visual['legenda']['faixas']);
        $this->assertSame(0, $visual['legenda']['sem_dado']);
        $this->assertSame(['Município', 'Região de saúde', 'Valor'], $visual['tabela']['colunas']);
        $this->assertCount(4, $visual['tabela']['linhas']);
        $this->assertArrayNotHasKey('ids', $visual['tabela']);
        $this->assertStringContainsString('vai de 60,0% (Cabo Frio) a 88,0% (Valença)', implode(' ', $visual['analise']['paragrafos']));
    }

    public function test_mapa_de_indicador_sem_nenhum_valor_e_sem_dado(): void
    {
        $semDados = Indicador::factory()->create(['codigo' => 'sem_valores', 'nome' => 'Indicador sem valores']);

        $visual = $this->construtor->construir(TipoDeVisual::Mapa, $semDados, $this->valenca, 24);

        $this->assertTrue($visual['sem_dado']);
        $this->assertNull($visual['grafico']);
    }

    public function test_matriz_sem_indices_calculados_e_sem_dado(): void
    {
        $visual = $this->construtor->construir(TipoDeVisual::Matriz, $this->icsap, $this->valenca, 24);

        $this->assertTrue($visual['sem_dado']);
        $this->assertSame('Matriz de prioridade: necessidade × desempenho', $visual['titulo']);
    }

    public function test_sem_dados_retorna_estado_vazio_com_explicacao_para_todos_os_tipos(): void
    {
        foreach ([TipoDeVisual::Destaque, TipoDeVisual::Evolucao, TipoDeVisual::Ranking] as $tipo) {
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
