<?php

namespace Tests\Feature;

use App\Domain\Indicators\CalculadorDeBenchmarks;
use App\Enums\EscopoBenchmark;
use App\Enums\OrigemExecucao;
use App\Enums\StatusIngestao;
use App\Enums\StatusIntegracao;
use App\Integrations\Ingestor;
use App\Integrations\RegistroDeConectores;
use App\Jobs\IngerirFonte;
use App\Models\Benchmark;
use App\Models\Indicador;
use App\Models\Ingestao;
use App\Models\Integracao;
use App\Models\Municipio;
use App\Models\User;
use App\Models\ValorIndicador;
use App\Support\VersaoDosDados;
use Database\Seeders\IndicadorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['aps.ufs' => ['RJ'], 'aps.http.tentativas' => 1, 'aps.http.espera_ms' => 0, 'aps.http.pausa_ms' => 0]);

        $this->seed(IndicadorSeeder::class);

        Municipio::factory()->create(['id' => 3306107, 'codigo6' => 330610, 'regiao_saude_codigo' => 33004]);
        Municipio::factory()->create(['id' => 3304201, 'codigo6' => 330420, 'regiao_saude_codigo' => 33004]);
        Municipio::factory()->create(['id' => 3301009, 'codigo6' => 330100, 'regiao_saude_codigo' => 33001]);
    }

    private function respostaDoEgestor(): array
    {
        $linha = fn (float $cobertura): array => ['nuComp' => '07/2026', 'qtPopulacao' => 70000, 'qtEsf' => 20, 'qtCapacidadeEquipe' => 70000, 'qtCobertura' => $cobertura];

        return [
            'coMunicipio=330610' => $linha(100.0),
            'coMunicipio=330420' => $linha(80.0),
            'coMunicipio=330100' => $linha(60.0),
        ];
    }

    private function fingirEgestor(): void
    {
        $respostas = $this->respostaDoEgestor();

        Http::fake(function ($request) use ($respostas) {
            if (! str_contains($request->url(), 'cobertura/aps')) {
                return Http::response([]);
            }

            foreach ($respostas as $trecho => $linha) {
                if (str_contains($request->url(), $trecho)) {
                    return Http::response([$linha]);
                }
            }

            return Http::response([]);
        });
    }

    public function test_execucao_bem_sucedida_registra_historico_atualiza_a_integracao_e_calcula_benchmarks(): void
    {
        $this->fingirEgestor();
        $versaoAntes = VersaoDosDados::atual();
        $usuario = User::factory()->admin()->create();
        $integracao = Integracao::factory()->daFonte('egestor')->create();

        $ingestao = app(Ingestor::class)->executar($integracao, OrigemExecucao::Manual, $usuario->id, 3);

        $this->assertSame(StatusIngestao::Sucesso, $ingestao->status);
        $this->assertNull($ingestao->mensagem);
        $this->assertSame(3 * 4, $ingestao->linhas, '3 municípios × (cobertura APS + cobertura ESF + equipes + equipes por 10 mil)');
        $this->assertSame(202607, $ingestao->competencia_mais_recente);
        $this->assertNotNull($ingestao->finalizada_em);
        $this->assertSame($usuario->id, $ingestao->user_id);

        $integracao->refresh();
        $this->assertSame(StatusIntegracao::Ok, $integracao->status);
        $this->assertNotNull($integracao->ultimo_sucesso_em);
        $this->assertSame(202607, $integracao->ultima_competencia);
        $this->assertSame(12, $integracao->ultimas_linhas);
        $this->assertNull($integracao->ultimo_erro);

        $this->assertNotSame($versaoAntes, VersaoDosDados::atual(), 'dados novos renovam a versão usada no cache dos visuais');
        $this->assertDatabaseHas('audit_logs', ['evento' => 'integracao_executada', 'user_id' => $usuario->id]);

        $cobertura = Indicador::where('codigo', 'cobertura_aps')->value('id');
        $regiao = Benchmark::where('indicador_id', $cobertura)->where('escopo', EscopoBenchmark::RegiaoSaude->value)->where('escopo_id', 33004)->firstOrFail();

        $this->assertSame(2, $regiao->quantidade);
        $this->assertEqualsWithDelta(90.0, $regiao->mediana, 0.0001);

        $uf = Benchmark::where('indicador_id', $cobertura)->where('escopo', EscopoBenchmark::Uf->value)->where('escopo_id', 33)->firstOrFail();
        $this->assertSame(3, $uf->quantidade);
        $this->assertEqualsWithDelta(80.0, $uf->mediana, 0.0001);
    }

    public function test_progresso_e_gravado_no_historico_e_informado_ao_ouvinte(): void
    {
        $this->fingirEgestor();
        $integracao = Integracao::factory()->daFonte('egestor')->create();
        $chamadas = [];

        $ingestao = app(Ingestor::class)->executar($integracao, OrigemExecucao::Comando, meses: 3, aoProgredir: function (int $feitos, ?int $total, ?string $etapa) use (&$chamadas): void {
            $chamadas[] = [$feitos, $total, $etapa];
        });

        $ingestao->refresh();
        $this->assertSame('Coberturas e equipes por município', $ingestao->etapa);
        $this->assertSame(3, $ingestao->passos_total);
        $this->assertSame(3, $ingestao->passos_concluidos);
        $this->assertSame(1.0, $ingestao->fracaoConcluida());

        $this->assertSame([0, 3, 'Coberturas e equipes por município'], $chamadas[0]);
        $this->assertSame([3, 3, 'Coberturas e equipes por município'], $chamadas[array_key_last($chamadas)]);
        $this->assertContains([2, 3, 'Coberturas e equipes por município'], $chamadas);
    }

    public function test_apenas_piloto_busca_so_os_municipios_do_piloto(): void
    {
        Municipio::whereKey(3306107)->update(['piloto' => true]);
        Municipio::whereKeyNot(3306107)->update(['piloto' => false]);
        $this->fingirEgestor();
        $integracao = Integracao::factory()->daFonte('egestor')->create();

        $ingestao = app(Ingestor::class)->executar($integracao, OrigemExecucao::Comando, meses: 3, apenasPiloto: true)->refresh();

        $this->assertSame(1, $ingestao->passos_total);
        $this->assertSame(4, $ingestao->linhas);
        $this->assertSame([3306107], ValorIndicador::distinct()->pluck('municipio_id')->all());
    }

    public function test_municipios_do_piloto_sao_processados_primeiro(): void
    {
        Municipio::query()->update(['piloto' => false]);
        Municipio::whereKey(3306107)->update(['piloto' => true]);
        $this->fingirEgestor();
        $integracao = Integracao::factory()->daFonte('egestor')->create();

        app(Ingestor::class)->executar($integracao, OrigemExecucao::Comando, meses: 3);

        $primeiroPedido = collect(Http::recorded())->first()[0]->url();
        $this->assertStringContainsString('coMunicipio=330610', $primeiroPedido, 'o piloto (Valença) vem antes dos demais, mesmo com código IBGE maior');
    }

    public function test_estimativa_de_tempo_restante_so_aparece_com_base_suficiente(): void
    {
        $agora = now();
        $execucao = Ingestao::factory()->make(['integracao_id' => 1, 'iniciada_em' => $agora->copy()->subSeconds(100), 'passos_total' => 200, 'passos_concluidos' => 50]);

        $this->assertSame(0.25, $execucao->fracaoConcluida());
        $this->assertSame(300, $execucao->segundosRestantes($agora), '100 s para 25% => 300 s para os 75% restantes');

        $this->assertNull(Ingestao::factory()->make(['integracao_id' => 1, 'iniciada_em' => $agora->copy()->subSeconds(10), 'passos_total' => 200, 'passos_concluidos' => 50])->segundosRestantes($agora));
        $this->assertNull(Ingestao::factory()->make(['integracao_id' => 1, 'iniciada_em' => $agora->copy()->subSeconds(100), 'passos_total' => 200, 'passos_concluidos' => 0])->segundosRestantes($agora));
        $this->assertNull(Ingestao::factory()->make(['integracao_id' => 1, 'iniciada_em' => $agora->copy()->subSeconds(100), 'passos_total' => null, 'passos_concluidos' => 7])->fracaoConcluida());
    }

    public function test_reexecutar_nao_duplica_valores_nem_benchmarks(): void
    {
        $this->fingirEgestor();
        $integracao = Integracao::factory()->daFonte('egestor')->create();

        app(Ingestor::class)->executar($integracao, OrigemExecucao::Comando, meses: 3);
        $valores = ValorIndicador::count();
        $benchmarks = Benchmark::count();

        app(Ingestor::class)->executar($integracao->fresh(), OrigemExecucao::Comando, meses: 3);

        $this->assertSame($valores, ValorIndicador::count());
        $this->assertSame($benchmarks, Benchmark::count());
        $this->assertSame(2, $integracao->ingestoes()->count());
    }

    public function test_falha_registra_erro_sem_perder_o_ultimo_sucesso(): void
    {
        $integracao = Integracao::factory()->daFonte('egestor')->create([
            'status' => StatusIntegracao::Ok,
            'ultimo_sucesso_em' => now()->subDay(),
            'ultima_competencia' => 202606,
            'ultimas_linhas' => 50,
        ]);
        $sucessoAnterior = $integracao->ultimo_sucesso_em;

        Http::fake(['*' => Http::response('Falha interna do servidor!!!', 500)]);

        $ingestao = app(Ingestor::class)->executar($integracao, OrigemExecucao::Agendada);

        $this->assertSame(StatusIngestao::Erro, $ingestao->status);

        $integracao->refresh();
        $this->assertSame(StatusIntegracao::Erro, $integracao->status);
        $this->assertNotNull($integracao->ultimo_erro);
        $this->assertTrue($integracao->ultimo_sucesso_em->equalTo($sucessoAnterior));
        $this->assertSame(202606, $integracao->ultima_competencia);
        $this->assertSame(50, $integracao->ultimas_linhas);
    }

    public function test_apos_o_primeiro_sucesso_so_os_meses_recentes_sao_buscados(): void
    {
        Http::fake(['*' => Http::response([])]);

        $integracao = Integracao::factory()->daFonte('egestor')->create(['config' => ['meses_historico' => 24]]);

        app(Ingestor::class)->executar($integracao, OrigemExecucao::Comando);
        $integracao->update(['ultimo_sucesso_em' => now()]);
        app(Ingestor::class)->executar($integracao->fresh(), OrigemExecucao::Comando);

        $janelas = collect(Http::recorded())->map(function ($par) {
            parse_str((string) parse_url($par[0]->url(), PHP_URL_QUERY), $consulta);

            return (int) $consulta['nuCompInicio'];
        })->unique()->sort()->values()->all();

        $this->assertSame([(int) now()->startOfMonth()->subMonthsNoOverflow(23)->format('Ym'), (int) now()->startOfMonth()->subMonthsNoOverflow(5)->format('Ym')], $janelas);
    }

    public function test_a_janela_de_atualizacao_de_rotina_cobre_a_defasagem_de_publicacao_de_cada_fonte(): void
    {
        $conectores = RegistroDeConectores::todos();

        $this->assertSame(6, $conectores['egestor']->mesesDeAtualizacao(), 'o e-Gestor publica com ~3 meses de atraso: a janela precisa ser bem maior que isso');
        $this->assertSame(3, $conectores['transparencia']->mesesDeAtualizacao());
    }

    public function test_enfileirar_marca_na_fila_dispara_o_job_e_nao_duplica(): void
    {
        Queue::fake();
        $integracao = Integracao::factory()->daFonte('ibge')->create();

        $this->assertTrue(app(Ingestor::class)->enfileirar($integracao, OrigemExecucao::Manual, 1, 12));
        $this->assertFalse(app(Ingestor::class)->enfileirar($integracao->fresh(), OrigemExecucao::Manual, 1, 12));

        Queue::assertPushed(IngerirFonte::class, 1);
        $this->assertSame(StatusIntegracao::NaFila, $integracao->fresh()->status);
    }

    public function test_job_marca_erro_quando_o_processo_morre_e_nao_fica_atualizando_para_sempre(): void
    {
        $integracao = Integracao::factory()->daFonte('ibge')->create(['status' => StatusIntegracao::Executando]);

        (new IngerirFonte($integracao->id))->failed(new \RuntimeException('tempo esgotado'));

        $integracao->refresh();
        $this->assertSame(StatusIntegracao::Erro, $integracao->status);
        $this->assertStringContainsString('interrompida', $integracao->ultimo_erro);
    }

    public function test_o_retry_after_da_fila_e_maior_que_o_tempo_maximo_do_job(): void
    {
        $this->assertGreaterThan(
            (new IngerirFonte(1))->timeout,
            config('queue.connections.database.retry_after'),
            'com retry_after menor que o job, cargas longas voltam para a fila e falham com MaxAttemptsExceededException',
        );
    }

    public function test_job_interrompido_tambem_encerra_a_execucao_aberta_no_historico(): void
    {
        $integracao = Integracao::factory()->daFonte('transparencia')->create(['status' => StatusIntegracao::Executando]);
        $aberta = Ingestao::factory()->create(['integracao_id' => $integracao->id, 'status' => StatusIngestao::Executando, 'finalizada_em' => null]);
        $outra = Ingestao::factory()->create(['status' => StatusIngestao::Executando, 'finalizada_em' => null]);

        (new IngerirFonte($integracao->id))->failed(new MaxAttemptsExceededException('tentativas esgotadas'));

        $aberta->refresh();
        $this->assertSame(StatusIngestao::Erro, $aberta->status);
        $this->assertNotNull($aberta->finalizada_em);
        $this->assertStringContainsString('MaxAttemptsExceededException', $aberta->mensagem);
        $this->assertSame(StatusIngestao::Executando, $outra->fresh()->status, 'execuções de outras integrações não são afetadas');
    }

    public function test_job_nao_executa_integracao_pausada_quando_agendado(): void
    {
        Http::fake();
        $integracao = Integracao::factory()->daFonte('ibge')->create(['ativa' => false]);

        (new IngerirFonte($integracao->id, OrigemExecucao::Agendada))->handle(app(Ingestor::class));

        Http::assertNothingSent();
        $this->assertSame(0, $integracao->ingestoes()->count());
    }

    public function test_calculador_nao_cria_escopo_brasil_sem_as_27_ufs_e_ignora_nulos(): void
    {
        $indicador = Indicador::where('codigo', 'cobertura_aps')->value('id');

        ValorIndicador::gravarEmLote([
            ['municipio_id' => 3306107, 'indicador_id' => $indicador, 'competencia' => 202607, 'valor' => 10.0],
            ['municipio_id' => 3304201, 'indicador_id' => $indicador, 'competencia' => 202607, 'valor' => 30.0],
            ['municipio_id' => 3301009, 'indicador_id' => $indicador, 'competencia' => 202607, 'valor' => null],
        ]);

        $gravadas = app(CalculadorDeBenchmarks::class)->calcular([$indicador => [202607]]);

        $this->assertSame(2, $gravadas, 'região 33004 e UF 33');
        $this->assertSame(0, Benchmark::where('escopo', EscopoBenchmark::Brasil->value)->count());

        $uf = Benchmark::where('escopo', EscopoBenchmark::Uf->value)->firstOrFail();
        $this->assertSame(2, $uf->quantidade);
        $this->assertSame(20.0, $uf->mediana);
        $this->assertSame(10.0, $uf->minimo);
        $this->assertSame(30.0, $uf->maximo);
        $this->assertSame(15.0, $uf->p25);
        $this->assertSame(25.0, $uf->p75);

        $this->assertSame(2, app(CalculadorDeBenchmarks::class)->recalcularTudo());
    }
}
