<?php

namespace Tests\Feature;

use App\Enums\Frequencia;
use App\Enums\OrigemExecucao;
use App\Enums\StatusIntegracao;
use App\Jobs\IngerirFonte;
use App\Models\Integracao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AgendamentoDeIntegracoesTest extends TestCase
{
    use RefreshDatabase;

    private function agora(string $data): Carbon
    {
        return Carbon::parse($data, 'America/Sao_Paulo');
    }

    public function test_diaria_vence_depois_do_horario_se_nao_rodou_hoje(): void
    {
        $integracao = Integracao::factory()->create(['frequencia' => Frequencia::Diaria, 'horario' => '03:00:00']);

        $integracao->update(['ultima_execucao_em' => $this->agora('2026-10-06 03:05'), 'status' => StatusIntegracao::Ok]);

        $this->assertFalse($integracao->estaVencida($this->agora('2026-10-07 02:59')));
        $this->assertTrue($integracao->estaVencida($this->agora('2026-10-07 03:01')));

        $integracao->update(['ultima_execucao_em' => $this->agora('2026-10-07 03:05')]);

        $this->assertFalse($integracao->estaVencida($this->agora('2026-10-07 15:00')));
        $this->assertTrue($integracao->estaVencida($this->agora('2026-10-08 03:01')));
    }

    public function test_antes_do_horario_considera_o_dia_anterior(): void
    {
        $integracao = Integracao::factory()->create(['frequencia' => Frequencia::Diaria, 'horario' => '03:00:00']);

        $this->assertEquals($this->agora('2026-10-06 03:00'), $integracao->ultimoHorarioPrevisto($this->agora('2026-10-07 02:00')));
        $this->assertEquals($this->agora('2026-10-07 03:00'), $integracao->ultimoHorarioPrevisto($this->agora('2026-10-07 03:00')));
        $this->assertEquals($this->agora('2026-10-08 03:00'), $integracao->proximoHorario($this->agora('2026-10-07 10:00')));
    }

    public function test_semanal_roda_toda_segunda(): void
    {
        $integracao = Integracao::factory()->create(['frequencia' => Frequencia::Semanal, 'horario' => '04:30:00']);

        // 2026-10-07 é quarta-feira; a última segunda foi 2026-10-05.
        $this->assertEquals($this->agora('2026-10-05 04:30'), $integracao->ultimoHorarioPrevisto($this->agora('2026-10-07 10:00')));
        $this->assertEquals($this->agora('2026-10-12 04:30'), $integracao->proximoHorario($this->agora('2026-10-07 10:00')));

        $integracao->update(['ultima_execucao_em' => $this->agora('2026-10-05 04:40'), 'status' => StatusIntegracao::Ok]);

        $this->assertFalse($integracao->estaVencida($this->agora('2026-10-07 10:00')));
        $this->assertTrue($integracao->estaVencida($this->agora('2026-10-12 05:00')));
    }

    public function test_mensal_roda_no_dia_2(): void
    {
        $integracao = Integracao::factory()->create(['frequencia' => Frequencia::Mensal, 'horario' => '03:00:00']);

        $this->assertEquals($this->agora('2026-10-02 03:00'), $integracao->ultimoHorarioPrevisto($this->agora('2026-10-07 10:00')));
        $this->assertEquals($this->agora('2026-09-02 03:00'), $integracao->ultimoHorarioPrevisto($this->agora('2026-10-01 10:00')));
        $this->assertEquals($this->agora('2026-11-02 03:00'), $integracao->proximoHorario($this->agora('2026-10-07 10:00')));
    }

    public function test_manual_pausada_ou_em_andamento_nunca_vencem(): void
    {
        $agora = $this->agora('2026-10-07 10:00');

        $this->assertFalse(Integracao::factory()->create(['frequencia' => Frequencia::Manual])->estaVencida($agora));
        $this->assertNull(Integracao::factory()->make(['frequencia' => Frequencia::Manual])->proximoHorario($agora));
        $this->assertFalse(Integracao::factory()->create(['fonte' => 'a', 'ativa' => false])->estaVencida($agora));
        $this->assertFalse(Integracao::factory()->create(['fonte' => 'b', 'status' => StatusIntegracao::Executando])->estaVencida($agora));
    }

    public function test_falha_e_repetida_depois_de_algumas_horas_mas_nao_antes(): void
    {
        $integracao = Integracao::factory()->create([
            'frequencia' => Frequencia::Diaria,
            'horario' => '03:00:00',
            'status' => StatusIntegracao::Erro,
            'ultima_execucao_em' => $this->agora('2026-10-07 03:05'),
        ]);

        $this->assertFalse($integracao->estaVencida($this->agora('2026-10-07 05:00')));
        $this->assertTrue($integracao->estaVencida($this->agora('2026-10-07 09:10')));
    }

    public function test_execucao_sem_sinal_de_vida_por_horas_e_considerada_travada(): void
    {
        $integracao = Integracao::factory()->create(['status' => StatusIntegracao::Executando]);
        $integracao->forceFill(['updated_at' => $this->agora('2026-10-07 07:00')])->saveQuietly();
        $integracao->refresh();

        $this->assertFalse($integracao->estaTravada($this->agora('2026-10-07 08:00')));
        $this->assertTrue($integracao->estaTravada($this->agora('2026-10-07 09:30')));
        $this->assertTrue($integracao->estaVencida($this->agora('2026-10-07 09:30')));
    }

    public function test_o_agendador_enfileira_apenas_as_integracoes_vencidas(): void
    {
        Queue::fake();
        Carbon::setTestNow($this->agora('2026-10-07 10:00'));

        $vencida = Integracao::factory()->create(['fonte' => 'ibge', 'frequencia' => Frequencia::Diaria]);
        Integracao::factory()->create(['fonte' => 'egestor', 'frequencia' => Frequencia::Diaria, 'ultima_execucao_em' => $this->agora('2026-10-07 03:10'), 'status' => StatusIntegracao::Ok]);
        Integracao::factory()->create(['fonte' => 'dados_abertos', 'frequencia' => Frequencia::Manual]);
        Integracao::factory()->create(['fonte' => 'transparencia', 'frequencia' => Frequencia::Manual]);
        Integracao::factory()->create(['fonte' => 'datasus_sih', 'frequencia' => Frequencia::Manual]);
        Integracao::factory()->create(['fonte' => 'datasus_sim_sinasc', 'frequencia' => Frequencia::Manual]);

        $this->artisan('schedule:run')->assertSuccessful();

        Queue::assertPushed(IngerirFonte::class, 1);
        Queue::assertPushed(IngerirFonte::class, fn (IngerirFonte $job): bool => $job->integracaoId === $vencida->id && $job->origem === OrigemExecucao::Agendada);
        $this->assertSame(StatusIntegracao::NaFila, $vencida->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_o_job_e_unico_por_fonte(): void
    {
        $this->assertSame('ingerir-fonte-7', (new IngerirFonte(7))->uniqueId());
    }
}
