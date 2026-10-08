<?php

namespace Tests\Feature;

use App\Enums\OrigemExecucao;
use App\Jobs\IngerirFonte;
use App\Models\Integracao;
use App\Models\Municipio;
use Database\Seeders\IndicadorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngerirFontesComandoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['aps.http.tentativas' => 1, 'aps.http.espera_ms' => 0, 'aps.http.pausa_ms' => 0]);
    }

    public function test_exige_fonte_ou_todas(): void
    {
        $this->artisan('aps:ingerir')->assertFailed();
    }

    public function test_recusa_fonte_desconhecida_e_meses_fora_do_limite(): void
    {
        $this->artisan('aps:ingerir', ['fonte' => 'inexistente'])->assertFailed();
        $this->artisan('aps:ingerir', ['fonte' => 'ibge', '--meses' => 500])->assertFailed();
    }

    public function test_executa_uma_fonte_e_registra_a_ingestao_como_linha_de_comando(): void
    {
        $this->seed(IndicadorSeeder::class);
        Http::fake(['apisidra.ibge.gov.br/*' => Http::response([['D1C' => 'x']])]);

        $this->artisan('aps:ingerir', ['fonte' => 'ibge'])->assertSuccessful();

        $ingestao = Integracao::where('fonte', 'ibge')->firstOrFail()->ingestoes()->firstOrFail();
        $this->assertSame(OrigemExecucao::Comando, $ingestao->origem);
    }

    public function test_retorna_falha_quando_a_fonte_falha(): void
    {
        Http::fake(['*' => Http::response('erro', 500)]);

        $this->artisan('aps:ingerir', ['fonte' => 'ibge'])->assertFailed();
    }

    public function test_mostra_a_etapa_e_a_barra_de_progresso_e_aceita_somente_piloto(): void
    {
        $this->seed(IndicadorSeeder::class);
        Municipio::factory()->piloto()->create(['id' => 3306107, 'codigo6' => 330610]);
        Municipio::factory()->create(['id' => 3304201, 'codigo6' => 330420]);
        Http::fake(['*' => Http::response([])]);

        $codigoDeSaida = Artisan::call('aps:ingerir', ['fonte' => 'egestor', '--piloto' => true, '--meses' => 1]);
        $saida = Artisan::output();

        $this->assertSame(0, $codigoDeSaida);
        $this->assertStringContainsString('somente piloto', $saida);
        $this->assertStringContainsString('Coberturas e equipes por município', $saida);
        $this->assertStringContainsString('1/1', $saida);
        $this->assertStringContainsString('100%', $saida);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'coMunicipio=330420'));
    }

    public function test_piloto_nao_combina_com_fila(): void
    {
        $this->artisan('aps:ingerir', ['fonte' => 'ibge', '--piloto' => true, '--fila' => true])->assertFailed();
    }

    public function test_com_fila_apenas_enfileira(): void
    {
        Queue::fake();

        $this->artisan('aps:ingerir', ['fonte' => 'ibge', '--fila' => true])->assertSuccessful();

        Queue::assertPushed(IngerirFonte::class, fn (IngerirFonte $job): bool => $job->origem === OrigemExecucao::Comando);
    }

    public function test_todas_pula_as_fontes_pausadas_como_a_que_exige_chave(): void
    {
        Queue::fake();

        $this->artisan('aps:ingerir', ['--todas' => true, '--fila' => true])->assertSuccessful();

        Queue::assertPushed(IngerirFonte::class, 5);
        $this->assertFalse(Integracao::where('fonte', 'transparencia')->firstOrFail()->ativa);
    }
}
