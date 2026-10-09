<?php

namespace Tests\Feature;

use App\Domain\Scoring\CalculadorDeIndices;
use App\Enums\OrigemExecucao;
use App\Enums\StatusIngestao;
use App\Integrations\Datasus\Contratos\BaixadorDeArquivos;
use App\Integrations\Datasus\Contratos\LeitorDeRegistros;
use App\Integrations\Datasus\NomesDeArquivo;
use App\Integrations\Ingestor;
use App\Models\Indicador;
use App\Models\Ingestao;
use App\Models\Integracao;
use App\Models\Municipio;
use App\Models\ValorIndicador;
use Database\Seeders\IndicadorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\Fakes\DatasusFalso;
use Tests\TestCase;

class ConectoresDatasusTest extends TestCase
{
    use RefreshDatabase;

    private const VALENCA = 3306107;

    private const RESENDE = 3304201;

    private DatasusFalso $datasus;

    private string $pastaTemporaria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pastaTemporaria = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aps-datasus-'.uniqid();
        config(['aps.ufs' => ['RJ'], 'aps.datasus.diretorio_temporario' => $this->pastaTemporaria]);
        Cache::flush();

        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'America/Sao_Paulo'));

        $this->datasus = new DatasusFalso;
        $this->app->instance(BaixadorDeArquivos::class, $this->datasus);
        $this->app->instance(LeitorDeRegistros::class, $this->datasus);

        $this->seed(IndicadorSeeder::class);

        Municipio::factory()->create(['id' => self::VALENCA, 'codigo6' => 330610, 'nome' => 'Valença', 'regiao_saude_codigo' => 33004]);
        Municipio::factory()->create(['id' => self::RESENDE, 'codigo6' => 330420, 'nome' => 'Resende', 'regiao_saude_codigo' => 33004]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->pastaTemporaria);
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function executar(string $fonte, ?int $meses = null, bool $reprocessar = false): Ingestao
    {
        $integracao = Integracao::firstOrCreate(['fonte' => $fonte], Integracao::factory()->daFonte($fonte)->raw());

        return app(Ingestor::class)->executar($integracao->fresh(), OrigemExecucao::Comando, meses: $meses, reprocessar: $reprocessar);
    }

    private function valor(string $codigo, int $municipio, int $competencia): ?ValorIndicador
    {
        return ValorIndicador::where('municipio_id', $municipio)
            ->where('indicador_id', Indicador::where('codigo', $codigo)->value('id'))
            ->where('competencia', $competencia)
            ->first();
    }

    private function populacaoDeValenca(float $habitantes): void
    {
        ValorIndicador::gravarEmLote([[
            'municipio_id' => self::VALENCA,
            'indicador_id' => Indicador::where('codigo', 'populacao_total')->value('id'),
            'competencia' => 202512,
            'valor' => $habitantes,
        ]]);
    }

    /**
     * Publica o SIH de 202510 a 202609: em cada mês Valença tem 1 internação sensível, 3 outras e 1 parto.
     */
    private function publicarSihDeUmAno(): void
    {
        $mes = Carbon::create(2025, 10, 1);

        for ($i = 0; $i < 12; $i++, $mes = $mes->addMonthNoOverflow()) {
            $linha = fn (string $diagnostico): array => [
                'ANO_CMPT' => $mes->format('Y'),
                'MES_CMPT' => $mes->format('m'),
                'IDENT' => '1',
                'MUNIC_RES' => '330610',
                'DIAG_PRINC' => $diagnostico,
            ];

            $this->datasus->publicar(
                NomesDeArquivo::sih('RJ', (int) $mes->format('Y'), (int) $mes->format('n')),
                [$linha('I64'), $linha('S628'), $linha('S629'), $linha('K359'), $linha('O800')],
            );
        }
    }

    public function test_sih_grava_contagens_mensais_e_acumulados_de_12_meses(): void
    {
        $this->populacaoDeValenca(6000);
        $this->publicarSihDeUmAno();

        $ingestao = $this->executar('datasus_sih', 2);

        $this->assertNull($ingestao->mensagem);
        $this->assertSame(1.0, $this->valor('icsap_internacoes_mes', self::VALENCA, 202609)->valor);
        $this->assertSame(4.0, $this->valor('internacoes_clinicas_mes', self::VALENCA, 202609)->valor);
        $this->assertSame(0.0, $this->valor('icsap_internacoes_mes', self::RESENDE, 202609)->valor, 'município sem internações recebe zero, não lacuna');

        $taxa = $this->valor('icsap_taxa', self::VALENCA, 202609);
        $this->assertSame(20.0, $taxa->valor, '12 internações / 6 mil hab. x 10 mil');
        $this->assertSame(12.0, $taxa->numerador);
        $this->assertSame(6000.0, $taxa->denominador);

        $percentual = $this->valor('icsap_percentual', self::VALENCA, 202609);
        $this->assertSame(25.0, $percentual->valor, '12 sensíveis em 48 internações clínicas');
        $this->assertSame(48.0, $percentual->denominador);

        $this->assertNull($this->valor('icsap_percentual', self::RESENDE, 202609), 'sem internações no denominador não há percentual');
    }

    public function test_sih_nao_calcula_acumulado_quando_falta_algum_dos_12_meses(): void
    {
        $this->populacaoDeValenca(60000);
        $this->publicarSihDeUmAno();

        $this->executar('datasus_sih', 2);

        // 202610 ainda não foi publicado: a janela 202511–202610 está incompleta.
        $this->assertNull($this->valor('icsap_taxa', self::VALENCA, 202610));
        $this->assertNull($this->valor('icsap_internacoes_mes', self::VALENCA, 202610));
    }

    public function test_sih_nao_avisa_sobre_mes_recente_nao_publicado_e_cita_municipio_sem_populacao(): void
    {
        $this->populacaoDeValenca(60000);
        $this->publicarSihDeUmAno();

        $ingestao = $this->executar('datasus_sih', 2);

        $avisos = implode(' ', $ingestao->avisos ?? []);
        $this->assertStringNotContainsString('ausentes no meio', $avisos);
        $this->assertStringContainsString('Resende', $avisos, 'sem população de referência o aviso cita o município');
    }

    public function test_sih_avisa_quando_um_mes_do_meio_nao_foi_publicado(): void
    {
        $this->populacaoDeValenca(60000);
        $this->publicarSihDeUmAno();
        $this->datasus->remover(NomesDeArquivo::sih('RJ', 2026, 3));

        $ingestao = $this->executar('datasus_sih', 2);

        $this->assertSame(StatusIngestao::Parcial, $ingestao->status);
        $this->assertStringContainsString('03/2026', implode(' ', $ingestao->avisos));
        $this->assertNull($this->valor('icsap_taxa', self::VALENCA, 202609), 'com um mês faltando na janela não há acumulado');
    }

    public function test_arquivos_sao_apagados_depois_da_leitura(): void
    {
        $this->publicarSihDeUmAno();

        $this->executar('datasus_sih', 2);

        $this->assertCount(12, $this->datasus->baixados);
        foreach ($this->datasus->destinos as $destino) {
            $this->assertFileDoesNotExist($destino);
        }
    }

    public function test_arquivo_e_apagado_mesmo_quando_a_leitura_falha(): void
    {
        $this->publicarSihDeUmAno();
        $this->datasus->falhaAoLer = 'arquivo corrompido';

        $ingestao = $this->executar('datasus_sih', 2);

        $this->assertSame(StatusIngestao::Erro, $ingestao->status);
        $this->assertNotEmpty($this->datasus->destinos);
        foreach ($this->datasus->destinos as $destino) {
            $this->assertFileDoesNotExist($destino);
        }
    }

    public function test_execucao_de_rotina_pula_arquivos_que_nao_mudaram_mas_reprocessamento_baixa_de_novo(): void
    {
        $this->populacaoDeValenca(60000);
        $this->publicarSihDeUmAno();

        $this->executar('datasus_sih', 2);
        $this->assertCount(12, $this->datasus->baixados);

        $this->datasus->baixados = [];
        $this->executar('datasus_sih');
        $this->assertSame([], $this->datasus->baixados, 'rotina: nada mudou, nada é baixado');

        $this->executar('datasus_sih', 2);
        $this->assertSame([], $this->datasus->baixados, 'período escolhido também só busca o que é novo');

        $this->executar('datasus_sih', 2, reprocessar: true);
        $this->assertCount(12, $this->datasus->baixados, 'reprocessar pedido explicitamente lê tudo de novo');
    }

    public function test_falha_no_meio_da_carga_mantem_o_que_foi_lido_e_a_proxima_execucao_continua_de_onde_parou(): void
    {
        $this->populacaoDeValenca(6000);
        $this->publicarSihDeUmAno();
        $this->datasus->publicar(NomesDeArquivo::sih('RJ', 2025, 9), [['ANO_CMPT' => '2025', 'MES_CMPT' => '09', 'IDENT' => '1', 'MUNIC_RES' => '330610', 'DIAG_PRINC' => 'I64']]);
        $arquivoQueFalha = NomesDeArquivo::sih('RJ', 2026, 9);
        $this->datasus->falhaAoBaixar = [$arquivoQueFalha];

        $ingestao = $this->executar('datasus_sih', 3);

        $this->assertSame(StatusIngestao::Erro, $ingestao->status);
        $this->assertStringContainsString('já foram salvos', $ingestao->mensagem);
        $this->assertStringContainsString('FTP indisponível', $ingestao->mensagem);
        $this->assertNotNull($this->valor('icsap_internacoes_mes', self::VALENCA, 202510), 'meses lidos antes da falha ficam salvos');
        $this->assertNull($this->valor('icsap_internacoes_mes', self::VALENCA, 202609), 'o arquivo que falhou não foi salvo');
        $this->assertNotNull($this->valor('icsap_taxa', self::VALENCA, 202608), 'a taxa que já podia ser calculada é salva mesmo com o erro');

        $this->datasus->falhaAoBaixar = [];
        $this->datasus->baixados = [];

        $segunda = $this->executar('datasus_sih', 3);

        $this->assertSame([$arquivoQueFalha], $this->datasus->baixados, 'só o arquivo pendente é baixado: o resto já estava salvo');
        $this->assertNull($segunda->mensagem);
        $this->assertNotNull($this->valor('icsap_taxa', self::VALENCA, 202609));
    }

    public function test_rotina_baixa_apenas_o_arquivo_que_mudou(): void
    {
        $this->publicarSihDeUmAno();
        $this->executar('datasus_sih', 2);
        $this->datasus->baixados = [];

        $remoto = NomesDeArquivo::sih('RJ', 2026, 9);
        $this->datasus->publicar($remoto, [['ANO_CMPT' => '2026', 'MES_CMPT' => '09', 'IDENT' => '1', 'MUNIC_RES' => '330610', 'DIAG_PRINC' => 'I64']]);

        $this->executar('datasus_sih');

        $this->assertSame([$remoto], $this->datasus->baixados);
    }

    public function test_sih_sem_python_falha_com_mensagem_clara_e_sem_baixar_nada(): void
    {
        $this->publicarSihDeUmAno();
        $this->datasus->problemaDoAmbiente = 'Python 3 não encontrado ("python").';

        $ingestao = $this->executar('datasus_sih', 2);

        $this->assertSame(StatusIngestao::Erro, $ingestao->status);
        $this->assertStringContainsString('Python 3 não encontrado', $ingestao->mensagem);
        $this->assertSame([], $this->datasus->baixados);
    }

    public function test_sim_e_sinasc_calculam_mortalidade_pre_natal_e_baixo_peso_por_ano(): void
    {
        $nascido = fn (string $consultas, string $peso): array => ['CODMUNRES' => '330610', 'CONSULTAS' => $consultas, 'PESO' => $peso];
        $obito = fn (string $idade): array => ['TIPOBITO' => '2', 'CODMUNRES' => '330610', 'IDADE' => $idade];

        $this->datasus->publicar(NomesDeArquivo::sinasc('RJ', 2024), [
            $nascido('4', '3200'), $nascido('4', '2400'), $nascido('3', '3000'), $nascido('2', '3100'),
            $nascido('4', '3300'), $nascido('4', '3500'), $nascido('4', '2900'), $nascido('1', '3100'),
        ]);
        $this->datasus->publicar(NomesDeArquivo::sim('RJ', 2024), [$obito('215'), $obito('401'), $obito('472')]);

        $ingestao = $this->executar('datasus_sim_sinasc', 36);

        $this->assertNull($ingestao->mensagem);

        $mortalidade = $this->valor('mortalidade_infantil', self::VALENCA, 202412);
        $this->assertSame(125.0, $mortalidade->valor, '1 óbito em 8 nascidos = 125 por mil');
        $this->assertSame(1.0, $mortalidade->numerador);
        $this->assertSame(8.0, $mortalidade->denominador);

        $this->assertSame(62.5, $this->valor('pre_natal_7_consultas', self::VALENCA, 202412)->valor, '5 de 8 com 7+ consultas');
        $this->assertSame(12.5, $this->valor('baixo_peso_nascer', self::VALENCA, 202412)->valor, '1 de 8 abaixo de 2.500 g');
        $this->assertNull($this->valor('mortalidade_infantil', self::RESENDE, 202412), 'sem nascimentos não há taxa');
    }

    public function test_sim_sinasc_grava_pre_natal_mesmo_sem_o_arquivo_de_obitos(): void
    {
        $this->datasus->publicar(NomesDeArquivo::sinasc('RJ', 2024), [['CODMUNRES' => '330610', 'CONSULTAS' => '4', 'PESO' => '3000']]);

        $this->executar('datasus_sim_sinasc', 36);

        $this->assertSame(100.0, $this->valor('pre_natal_7_consultas', self::VALENCA, 202412)->valor);
        $this->assertNull($this->valor('mortalidade_infantil', self::VALENCA, 202412), 'sem o SIM do ano a mortalidade fica em branco, nunca zero');
    }

    public function test_sim_sinasc_pula_ano_inalterado_na_rotina(): void
    {
        $this->datasus->publicar(NomesDeArquivo::sinasc('RJ', 2024), [['CODMUNRES' => '330610', 'CONSULTAS' => '4', 'PESO' => '3000']]);
        $this->datasus->publicar(NomesDeArquivo::sim('RJ', 2024), []);

        $this->executar('datasus_sim_sinasc', 36);
        $this->datasus->baixados = [];

        $this->executar('datasus_sim_sinasc');

        $this->assertSame([], $this->datasus->baixados);
    }

    public function test_testar_conexao_informa_ambiente_ausente_e_arquivo_de_teste_inexistente(): void
    {
        $integracao = Integracao::factory()->daFonte('datasus_sih')->create();

        $this->datasus->problemaDoAmbiente = 'Python 3 não encontrado.';
        $this->assertFalse($integracao->conector()->testarConexao($integracao)->ok);

        $this->datasus->problemaDoAmbiente = null;
        $resultado = $integracao->conector()->testarConexao($integracao);
        $this->assertFalse($resultado->ok);
        $this->assertStringContainsString('não foi encontrado', $resultado->mensagem);

        $this->datasus->publicar(NomesDeArquivo::sih('RJ', 2025, 12), []);
        $this->assertTrue($integracao->conector()->testarConexao($integracao)->ok);
    }

    public function test_carga_que_altera_indicadores_da_metodologia_recalcula_os_indices(): void
    {
        $this->mock(CalculadorDeIndices::class, function ($mock): void {
            $mock->shouldReceive('afetadoPor')->once()->andReturn(true);
            $mock->shouldReceive('calcular')->once()->andReturn(['metodologia' => 1, 'linhas' => 0, 'ultima_competencia' => null, 'quadrantes' => [], 'avisos' => []]);
        });
        $this->publicarSihDeUmAno();

        $ingestao = $this->executar('datasus_sih', 2);

        $this->assertNull($ingestao->mensagem);
    }

    public function test_falha_ao_recalcular_os_indices_vira_aviso_e_nao_derruba_a_carga(): void
    {
        $this->mock(CalculadorDeIndices::class, function ($mock): void {
            $mock->shouldReceive('afetadoPor')->andReturn(true);
            $mock->shouldReceive('calcular')->andThrow(new RuntimeException('falha'));
        });
        $this->populacaoDeValenca(6000);
        $this->publicarSihDeUmAno();

        $ingestao = $this->executar('datasus_sih', 2);

        $this->assertNull($ingestao->mensagem, 'a carga em si deu certo');
        $this->assertStringContainsString('índices', implode(' ', $ingestao->avisos));
        $this->assertNotNull($this->valor('icsap_taxa', self::VALENCA, 202609), 'os dados foram salvos');
    }

    public function test_carga_que_nao_mexe_nos_indicadores_da_metodologia_nao_recalcula(): void
    {
        $this->mock(CalculadorDeIndices::class, function ($mock): void {
            $mock->shouldReceive('afetadoPor')->once()->andReturn(false);
            $mock->shouldNotReceive('calcular');
        });
        $this->publicarSihDeUmAno();

        $this->executar('datasus_sih', 2);
    }
}
