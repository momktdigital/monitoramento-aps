<?php

namespace Tests\Feature;

use App\Enums\OrigemExecucao;
use App\Integrations\Conectores\EGestorConector;
use App\Integrations\Ingestor;
use App\Models\Indicador;
use App\Models\Ingestao;
use App\Models\Integracao;
use App\Models\Municipio;
use App\Models\ValorIndicador;
use Database\Seeders\IndicadorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConectoresTest extends TestCase
{
    use RefreshDatabase;

    private const VALENCA = 3306107;

    private const RESENDE = 3304201;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'aps.ufs' => ['RJ'],
            'aps.http.tentativas' => 1,
            'aps.http.espera_ms' => 0,
            'aps.http.pausa_ms' => 0,
            'aps.transparencia.pausa_ms' => 0,
            'aps.transparencia.espera_apos_limite_s' => 0,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'America/Sao_Paulo'));

        $this->seed(IndicadorSeeder::class);

        Municipio::factory()->create(['id' => self::VALENCA, 'codigo6' => 330610, 'nome' => 'Valença', 'regiao_saude_codigo' => 33004]);
        Municipio::factory()->create(['id' => self::RESENDE, 'codigo6' => 330420, 'nome' => 'Resende', 'regiao_saude_codigo' => 33004]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function executar(string $fonte, ?int $meses = null): Ingestao
    {
        $integracao = Integracao::factory()->daFonte($fonte)->create();

        return app(Ingestor::class)->executar($integracao->fresh(), OrigemExecucao::Comando, meses: $meses);
    }

    private function valor(string $codigo, int $municipio, int $competencia): ?ValorIndicador
    {
        return ValorIndicador::where('municipio_id', $municipio)
            ->where('indicador_id', Indicador::where('codigo', $codigo)->value('id'))
            ->where('competencia', $competencia)
            ->first();
    }

    private function populacaoDe(int $municipio, int $ano, float $valor): void
    {
        ValorIndicador::gravarEmLote([[
            'municipio_id' => $municipio,
            'indicador_id' => Indicador::where('codigo', 'populacao_total')->value('id'),
            'competencia' => $ano * 100 + 12,
            'valor' => $valor,
        ]]);
    }

    private function linhaSidra(int $municipio, string $valor, string $ano = '2022', ?string $idade = null): array
    {
        return ['D1C' => (string) $municipio, 'V' => $valor, 'D3C' => $ano, 'D5C' => $idade ?? ''];
    }

    public function test_ibge_grava_populacao_estrutura_etaria_e_densidade(): void
    {
        $cabecalho = ['D1C' => 'Município (Código)', 'V' => 'Valor'];

        Http::fake([
            'apisidra.ibge.gov.br/values/t/6579/*' => Http::response([$cabecalho, $this->linhaSidra(self::VALENCA, '71442', '2026'), $this->linhaSidra(self::VALENCA, '71449', '2025'), $this->linhaSidra(3300100, '179152', '2026')]),
            'apisidra.ibge.gov.br/values/t/9514/*' => Http::response([
                $cabecalho,
                $this->linhaSidra(self::VALENCA, '1000', idade: '100362'),
                $this->linhaSidra(self::VALENCA, '100', idade: '93070'),
                $this->linhaSidra(self::VALENCA, '150', idade: '93095'),
                $this->linhaSidra(self::VALENCA, '50', idade: '93096'),
                $this->linhaSidra(self::VALENCA, '-', idade: '93097'),
                $this->linhaSidra(self::VALENCA, '10', idade: '6653'),
            ]),
            'apisidra.ibge.gov.br/values/t/4714/*' => Http::response([$cabecalho, $this->linhaSidra(self::VALENCA, '52,3')]),
        ]);

        $ingestao = $this->executar('ibge');

        $this->assertNull($ingestao->mensagem);
        $this->assertSame(71442.0, $this->valor('populacao_total', self::VALENCA, 202612)->valor);
        $this->assertSame(71449.0, $this->valor('populacao_total', self::VALENCA, 202512)->valor);
        $this->assertSame(1000.0, $this->valor('populacao_total', self::VALENCA, 202212)->valor);

        $criancas = $this->valor('pct_menores_5_anos', self::VALENCA, 202212);
        $this->assertSame(10.0, $criancas->valor);
        $this->assertSame(100.0, $criancas->numerador);
        $this->assertSame(1000.0, $criancas->denominador);

        $this->assertSame(21.0, $this->valor('pct_60_anos_ou_mais', self::VALENCA, 202212)->valor);
        $this->assertSame(52.3, $this->valor('densidade_demografica', self::VALENCA, 202212)->valor);

        $this->assertNull($this->valor('populacao_total', 3300100, 202612), 'municípios fora do universo configurado são ignorados');
    }

    public function test_ibge_resposta_de_erro_vira_falha_legivel_sem_gravar(): void
    {
        Http::fake(['apisidra.ibge.gov.br/*' => Http::response('Parâmetro V (Variável) com código 999 inexistente na tabela', 200)]);

        $ingestao = $this->executar('ibge');

        $this->assertSame('erro', $ingestao->status->value);
        $this->assertStringContainsString('Resposta inesperada do SIDRA', $ingestao->mensagem);
        $this->assertSame(0, ValorIndicador::count());
    }

    public function test_egestor_deriva_cobertura_esf_equipes_e_le_formatos_de_texto(): void
    {
        $this->populacaoDe(self::VALENCA, 2026, 71442);

        Http::fake([
            'relatorioaps-prd.saude.gov.br/cobertura/aps*' => Http::response([
                ['nuComp' => '07/2026', 'qtPopulacao' => 71449, 'qtEsf' => 23, 'qtCapacidadeEquipe' => 89250, 'qtCobertura' => 124.91],
                ['nuComp' => '06/2026', 'qtPopulacao' => 71449, 'qtEsf' => 23, 'qtCapacidadeEquipe' => 89250, 'qtCobertura' => 124.91],
            ]),
            'relatorioaps-prd.saude.gov.br/cobertura/acs*' => Http::response([
                ['nuComp' => '202607', 'qtCoberturaAcsAb' => '71,449', 'qtPopulacao' => '71,449', 'pcCoberturaAcsAb' => '100'],
            ]),
            'relatorioaps-prd.saude.gov.br/cobertura/sb/v2*' => Http::response([
                ['nuCompetencia' => '202607', 'qtPopulacao' => 71449, 'qtParametroCadastroEquipeSbAps' => 61250, 'pcCoberturaSbAps' => 85.73],
            ]),
        ]);

        $ingestao = $this->executar('egestor', 3);

        $this->assertNull($ingestao->mensagem);
        $this->assertSame(124.91, $this->valor('cobertura_aps', self::VALENCA, 202607)->valor);
        $this->assertEqualsWithDelta(112.6678, $this->valor('cobertura_esf', self::VALENCA, 202607)->valor, 0.0001);
        $this->assertSame(23.0, $this->valor('equipes_esf', self::VALENCA, 202607)->valor);
        $this->assertEqualsWithDelta(3.2191, $this->valor('equipes_esf_por_10mil', self::VALENCA, 202607)->valor, 0.0001);
        $this->assertSame(100.0, $this->valor('cobertura_acs', self::VALENCA, 202607)->valor);
        $this->assertSame(71449.0, $this->valor('cobertura_acs', self::VALENCA, 202607)->denominador);
        $this->assertSame(85.73, $this->valor('cobertura_saude_bucal', self::VALENCA, 202607)->valor);
        $this->assertNotNull($this->valor('cobertura_aps', self::VALENCA, 202606));
    }

    public function test_egestor_pede_so_a_janela_de_meses_e_o_municipio_certo(): void
    {
        Http::fake(['relatorioaps-prd.saude.gov.br/*' => Http::response([])]);

        $this->executar('egestor', 3);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'coMunicipio=330610')
            && str_contains($request->url(), 'nuCompInicio=202608')
            && str_contains($request->url(), 'nuCompFim=202610')
            && str_contains($request->url(), 'unidadeGeografica=MUNICIPIO'));
    }

    public function test_egestor_falha_em_um_municipio_vira_aviso_e_nao_derruba_os_demais(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'coMunicipio=330420')) {
                return Http::response('Falha interna do servidor!!!', 500);
            }

            return str_contains($request->url(), 'cobertura/aps')
                ? Http::response([['nuComp' => '07/2026', 'qtPopulacao' => 70000, 'qtEsf' => 20, 'qtCapacidadeEquipe' => 70000, 'qtCobertura' => 100.0]])
                : Http::response([]);
        });

        $ingestao = $this->executar('egestor', 3);

        $this->assertSame('parcial', $ingestao->status->value);
        $this->assertNull($ingestao->mensagem);
        $this->assertStringContainsString('Resende', $ingestao->avisos[0]);
        $this->assertNotNull($this->valor('cobertura_aps', self::VALENCA, 202607));
    }

    public function test_egestor_falha_total_e_erro_da_execucao(): void
    {
        Http::fake(['relatorioaps-prd.saude.gov.br/*' => Http::response('Falha interna do servidor!!!', 500)]);

        $ingestao = $this->executar('egestor', 3);

        $this->assertSame('erro', $ingestao->status->value);
        $this->assertStringContainsString('não respondeu para nenhum município', $ingestao->mensagem);
    }

    public function test_egestor_formato_inesperado_e_reportado_como_mudanca_da_api(): void
    {
        Http::fake(['relatorioaps-prd.saude.gov.br/*' => Http::response(['erro' => 'x'])]);

        $ingestao = $this->executar('egestor', 3);

        $this->assertSame('erro', $ingestao->status->value);
        $this->assertStringContainsString('Formato inesperado', $ingestao->avisos[0]);
    }

    public function test_cnes_conta_ubs_paginando_e_grava_zero_para_municipio_sem_unidade(): void
    {
        $this->populacaoDe(self::VALENCA, 2026, 70000);
        $this->populacaoDe(self::RESENDE, 2026, 130000);

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $consulta);
            $tipo = (int) $consulta['codigo_tipo_unidade'];
            $deslocamento = (int) $consulta['offset'];

            $itens = match (true) {
                $tipo === 2 && $deslocamento === 0 => array_fill(0, 20, ['codigo_municipio' => 330610]),
                $tipo === 2 && $deslocamento === 20 => [['codigo_municipio' => 330610], ['codigo_municipio' => 330610]],
                $tipo === 1 && $deslocamento === 0 => [['codigo_municipio' => 330610]],
                default => [],
            };

            return Http::response(['estabelecimentos' => $itens]);
        });

        $ingestao = $this->executar('dados_abertos');

        $this->assertNull($ingestao->mensagem);

        $valenca = $this->valor('ubs_por_10mil', self::VALENCA, 202610);
        $this->assertSame(23.0, $valenca->numerador);
        $this->assertEqualsWithDelta(23 / 70000 * 10000, $valenca->valor, 0.0001);

        $this->assertSame(0.0, $this->valor('ubs_por_10mil', self::RESENDE, 202610)->valor);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'status=1') && str_contains($request->url(), 'limit=20') && str_contains($request->url(), 'codigo_uf=33'));
    }

    public function test_cnes_sem_populacao_gera_aviso_em_vez_de_gravar_valor_errado(): void
    {
        Http::fake(['apidadosabertos.saude.gov.br/*' => Http::response(['estabelecimentos' => []])]);

        $ingestao = $this->executar('dados_abertos');

        $this->assertSame('parcial', $ingestao->status->value);
        $this->assertSame(0, ValorIndicador::count());
        $this->assertStringContainsString('população de referência', $ingestao->avisos[0]);
    }

    public function test_transparencia_envia_a_chave_no_cabecalho_soma_paginas_e_calcula_percentual(): void
    {
        $this->populacaoDe(self::VALENCA, 2026, 70000);
        $this->populacaoDe(self::RESENDE, 2026, 130000);

        $integracao = Integracao::factory()->daFonte('transparencia')->create(['config' => ['chave_api' => 'CHAVE-SECRETA-123']]);

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $consulta);

            if ((int) $consulta['codigoIbge'] !== self::VALENCA || (int) $consulta['mesAno'] !== 202610) {
                return Http::response([]);
            }

            if (str_contains($request->url(), 'bolsa-familia')) {
                return Http::response((int) $consulta['pagina'] === 1 ? [['quantidadeBeneficiados' => 7000]] : []);
            }

            // Página cheia (15 itens) indica que há mais páginas; a segunda, incompleta, encerra a busca.
            return Http::response(match ((int) $consulta['pagina']) {
                1 => array_fill(0, 15, ['quantidadeBeneficiados' => 100]),
                2 => [['quantidadeBeneficiados' => 100]],
                default => [],
            });
        });

        $ingestao = app(Ingestor::class)->executar($integracao->fresh(), OrigemExecucao::Comando, meses: 1);

        $this->assertNull($ingestao->mensagem);
        $this->assertEqualsWithDelta(10.0, $this->valor('pct_bolsa_familia', self::VALENCA, 202610)->valor, 0.0001);
        $this->assertSame(7000.0, $this->valor('pct_bolsa_familia', self::VALENCA, 202610)->numerador);
        $this->assertEqualsWithDelta(1600 / 70000 * 100, $this->valor('pct_bpc', self::VALENCA, 202610)->valor, 0.0001);
        $this->assertNull($this->valor('pct_bpc', self::RESENDE, 202610), 'mês não publicado para o município não gera valor');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('chave-api-dados', 'CHAVE-SECRETA-123'));
    }

    public function test_transparencia_sem_chave_falha_com_mensagem_orientando_o_administrador(): void
    {
        Http::fake();

        $ingestao = $this->executar('transparencia');

        $this->assertSame('erro', $ingestao->status->value);
        $this->assertStringContainsString('tela de Integrações', $ingestao->mensagem);
        Http::assertNothingSent();
    }

    public function test_transparencia_chave_recusada_vira_erro_claro_e_nao_vaza_a_chave(): void
    {
        $this->populacaoDe(self::VALENCA, 2026, 70000);
        $integracao = Integracao::factory()->daFonte('transparencia')->create(['config' => ['chave_api' => 'CHAVE-SECRETA-123']]);

        Http::fake(['api.portaldatransparencia.gov.br/*' => Http::response('chave CHAVE-SECRETA-123 inválida', 403)]);

        $ingestao = app(Ingestor::class)->executar($integracao->fresh(), OrigemExecucao::Comando, meses: 1);

        $this->assertSame('erro', $ingestao->status->value);
        $this->assertStringContainsString('recusou a chave', $ingestao->mensagem);
        $this->assertStringNotContainsString('CHAVE-SECRETA-123', $ingestao->mensagem);
        $this->assertStringNotContainsString('CHAVE-SECRETA-123', (string) $integracao->fresh()->ultimo_erro);
    }

    public function test_transparencia_tenta_de_novo_apos_limite_de_requisicoes(): void
    {
        $this->populacaoDe(self::VALENCA, 2026, 70000);
        $integracao = Integracao::factory()->daFonte('transparencia')->create(['config' => ['chave_api' => 'CHAVE-SECRETA-123']]);

        $chamadas = 0;
        Http::fake(function (Request $request) use (&$chamadas) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $consulta);

            if ((int) $consulta['codigoIbge'] !== self::VALENCA || (int) $consulta['mesAno'] !== 202610 || (int) $consulta['pagina'] > 1 || str_contains($request->url(), 'bpc')) {
                return Http::response([]);
            }

            return ++$chamadas === 1 ? Http::response('', 429) : Http::response([['quantidadeBeneficiados' => 700]]);
        });

        $ingestao = app(Ingestor::class)->executar($integracao->fresh(), OrigemExecucao::Comando, meses: 1);

        $this->assertNull($ingestao->mensagem);
        $this->assertEqualsWithDelta(1.0, $this->valor('pct_bolsa_familia', self::VALENCA, 202610)->valor, 0.0001);
    }

    public function test_teste_de_conexao_do_egestor_usa_janela_de_12_meses_e_funciona_com_publicacao_defasada(): void
    {
        // Hoje é out/2026, mas a última competência publicada é jul/2026: o teste não pode depender do mês atual.
        Http::fake(['relatorioaps-prd.saude.gov.br/*' => Http::response([
            ['nuComp' => '06/2026', 'qtPopulacao' => 71449],
            ['nuComp' => '07/2026', 'qtPopulacao' => 71449],
        ])]);

        $resultado = app(EGestorConector::class)->testarConexao(Integracao::factory()->daFonte('egestor')->make());

        $this->assertTrue($resultado->ok);
        $this->assertStringContainsString('jul/2026', $resultado->mensagem);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'nuCompInicio=202510') && str_contains($request->url(), 'nuCompFim=202610'));
    }

    public function test_teste_de_conexao_do_egestor_falha_com_mensagem_util_quando_nao_ha_dados_nem_resposta(): void
    {
        Http::fake(['relatorioaps-prd.saude.gov.br/*' => Http::response([])]);
        $conector = app(EGestorConector::class);
        $integracao = Integracao::factory()->daFonte('egestor')->make();

        $vazio = $conector->testarConexao($integracao);
        $this->assertFalse($vazio->ok);
        $this->assertStringContainsString('últimos 12 meses', $vazio->mensagem);

        Http::fake(['relatorioaps-prd.saude.gov.br/*' => Http::response('Falha interna do servidor!!!', 500)]);
        $this->assertFalse($conector->testarConexao($integracao)->ok);
    }

    public function test_transparencia_encerra_a_busca_em_pagina_incompleta_sem_pedir_a_seguinte(): void
    {
        $this->populacaoDe(self::VALENCA, 2026, 70000);
        $integracao = Integracao::factory()->daFonte('transparencia')->create(['config' => ['chave_api' => 'CHAVE-SECRETA-123']]);

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $consulta);

            return (int) $consulta['codigoIbge'] === self::VALENCA && (int) $consulta['mesAno'] === 202610
                ? Http::response([['quantidadeBeneficiados' => 700]])
                : Http::response([]);
        });

        app(Ingestor::class)->executar($integracao->fresh(), OrigemExecucao::Comando, meses: 1);

        $paginasPedidasDeValenca = collect(Http::recorded())
            ->filter(fn ($par): bool => str_contains($par[0]->url(), 'codigoIbge=3306107'))
            ->map(fn ($par): string => (string) $par[0]->url())
            ->filter(fn (string $url): bool => str_contains($url, 'pagina=2'));

        $this->assertCount(0, $paginasPedidasDeValenca, 'uma página com menos de 15 itens é a última: não há por que pedir a seguinte');
    }
}
