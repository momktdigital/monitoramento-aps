<?php

namespace Tests\Feature;

use App\Enums\StatusIngestao;
use App\Enums\StatusIntegracao;
use App\Models\Ingestao;
use App\Models\Integracao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestravarIntegracoesComandoTest extends TestCase
{
    use RefreshDatabase;

    public function test_nao_faz_nada_quando_nenhuma_integracao_esta_presa(): void
    {
        Integracao::factory()->daFonte('ibge')->create(['status' => StatusIntegracao::Ok]);

        $this->artisan('aps:destravar')->expectsOutputToContain('Nenhuma integração presa')->assertSuccessful();
    }

    public function test_libera_integracoes_na_fila_ou_em_andamento_e_fecha_a_execucao_aberta(): void
    {
        $presa = Integracao::factory()->daFonte('transparencia')->create(['status' => StatusIntegracao::Executando]);
        $naFila = Integracao::factory()->daFonte('egestor')->create(['status' => StatusIntegracao::NaFila]);
        $ok = Integracao::factory()->daFonte('ibge')->create(['status' => StatusIntegracao::Ok]);
        $aberta = Ingestao::factory()->create(['integracao_id' => $presa->id, 'status' => StatusIngestao::Executando, 'finalizada_em' => null]);
        $concluida = Ingestao::factory()->create(['integracao_id' => $ok->id, 'status' => StatusIngestao::Sucesso]);

        $this->artisan('aps:destravar')->assertSuccessful();

        $this->assertSame(StatusIntegracao::Erro, $presa->fresh()->status);
        $this->assertSame(StatusIntegracao::Erro, $naFila->fresh()->status);
        $this->assertSame(StatusIntegracao::Ok, $ok->fresh()->status);
        $this->assertSame(StatusIngestao::Erro, $aberta->fresh()->status);
        $this->assertNotNull($aberta->fresh()->finalizada_em);
        $this->assertSame(StatusIngestao::Sucesso, $concluida->fresh()->status);
    }

    public function test_pode_limitar_a_uma_fonte(): void
    {
        $transparencia = Integracao::factory()->daFonte('transparencia')->create(['status' => StatusIntegracao::Executando]);
        $egestor = Integracao::factory()->daFonte('egestor')->create(['status' => StatusIntegracao::Executando]);

        $this->artisan('aps:destravar', ['fonte' => 'transparencia'])->assertSuccessful();

        $this->assertSame(StatusIntegracao::Erro, $transparencia->fresh()->status);
        $this->assertSame(StatusIntegracao::Executando, $egestor->fresh()->status);
    }
}
