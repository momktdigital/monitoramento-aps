<?php

namespace Tests\Feature;

use App\Enums\OrigemExecucao;
use App\Enums\StatusIngestao;
use App\Enums\StatusIntegracao;
use App\Integrations\RegistroDeConectores;
use App\Jobs\IngerirFonte;
use App\Livewire\Integracoes\Painel;
use App\Models\Ingestao;
use App\Models\Integracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class PainelDeIntegracoesTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = 'CHAVE-SECRETA-ABC123';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['aps.http.tentativas' => 1, 'aps.http.espera_ms' => 0]);

        RegistroDeConectores::garantirIntegracoes();

        $this->admin = User::factory()->admin()->comDoisFatores()->create();
        RateLimiter::clear('integracao-forcar:'.$this->admin->id);
        RateLimiter::clear('integracao-teste:'.$this->admin->id);
    }

    private function painel(): Testable
    {
        return Livewire::actingAs($this->admin)->test(Painel::class);
    }

    private function transparencia(): Integracao
    {
        return Integracao::where('fonte', 'transparencia')->firstOrFail();
    }

    public function test_so_administradores_acessam_a_tela(): void
    {
        $this->get(route('admin.integracoes'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route('admin.integracoes'))->assertForbidden();
        $this->actingAs(User::factory()->gestor()->create())->get(route('admin.integracoes'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.integracoes'))->assertOk()->assertSee('Integrações');
    }

    public function test_o_endereco_antigo_redireciona_para_a_administracao(): void
    {
        $this->actingAs($this->admin)->get('/integracoes')->assertRedirect('/admin/integracoes');
    }

    public function test_usuario_sem_permissao_nao_consegue_usar_o_componente_diretamente(): void
    {
        Livewire::actingAs(User::factory()->gestor()->create())->test(Painel::class)->assertForbidden();
    }

    public function test_lista_todas_as_fontes_com_estado_e_a_que_exige_chave_aparece_pausada_e_pendente(): void
    {
        $this->painel()
            ->assertSee('IBGE (SIDRA)')
            ->assertSee('e-Gestor APS')
            ->assertSee('Dados Abertos do SUS')
            ->assertSee('Portal da Transparência')
            ->assertSee('Nunca executada')
            ->assertSee('Configuração pendente');

        $this->assertFalse($this->transparencia()->ativa);
        $this->assertTrue(Integracao::where('fonte', 'ibge')->firstOrFail()->ativa);
    }

    public function test_a_chave_e_salva_criptografada_nunca_exibida_e_ativa_a_fonte(): void
    {
        $componente = $this->painel()
            ->call('abrirConfiguracao', $this->transparencia()->id)
            ->assertSee('Não configurada')
            ->set('valores.chave_api', self::CHAVE)
            ->call('salvarConfiguracao')
            ->assertHasNoErrors();

        $transparencia = $this->transparencia();

        $this->assertTrue($transparencia->ativa, 'ao completar a configuração pela primeira vez, a integração é ativada');
        $this->assertSame(self::CHAVE, $transparencia->valorDeConfig('chave_api'));

        $bruto = DB::table('integracoes')->where('fonte', 'transparencia')->value('config');
        $this->assertStringNotContainsString(self::CHAVE, $bruto, 'a chave não pode estar em texto puro no banco');

        $componente->call('abrirConfiguracao', $transparencia->id)
            ->assertSee('Configurada')
            ->assertDontSee(self::CHAVE)
            ->assertSet('valores.chave_api', '');

        $auditoria = DB::table('audit_logs')->where('evento', 'integracao_configurada')->latest('id')->first();
        $this->assertStringNotContainsString(self::CHAVE, (string) $auditoria->dados);
        $this->assertStringContainsString('chave_api', (string) $auditoria->dados);
    }

    public function test_deixar_a_chave_em_branco_mantem_a_chave_existente(): void
    {
        $this->transparencia()->update(['config' => ['chave_api' => self::CHAVE]]);

        $this->painel()
            ->call('abrirConfiguracao', $this->transparencia()->id)
            ->set('valores.chave_api', '')
            ->set('frequencia', 'semanal')
            ->call('salvarConfiguracao')
            ->assertHasNoErrors();

        $this->assertSame(self::CHAVE, $this->transparencia()->valorDeConfig('chave_api'));
        $this->assertSame('semanal', $this->transparencia()->frequencia->value);
    }

    public function test_salvar_valida_frequencia_horario_chave_e_meses(): void
    {
        $this->painel()
            ->call('abrirConfiguracao', $this->transparencia()->id)
            ->set('frequencia', 'a-cada-segundo')
            ->set('horario', '25:99')
            ->set('valores.chave_api', "chave com espaço\n")
            ->set('valores.meses_historico', '9999')
            ->call('salvarConfiguracao')
            ->assertHasErrors(['frequencia', 'horario', 'valores.chave_api', 'valores.meses_historico']);
    }

    public function test_configuracao_de_parametros_nao_secretos_e_persistida(): void
    {
        $this->painel()
            ->call('abrirConfiguracao', Integracao::where('fonte', 'egestor')->firstOrFail()->id)
            ->set('valores.meses_historico', '18')
            ->set('horario', '04:15')
            ->call('salvarConfiguracao')
            ->assertHasNoErrors();

        $egestor = Integracao::where('fonte', 'egestor')->firstOrFail();

        $this->assertSame('18', $egestor->valorDeConfig('meses_historico'));
        $this->assertSame('04:15:00', $egestor->horario);
    }

    public function test_pausar_e_retomar(): void
    {
        $ibge = Integracao::where('fonte', 'ibge')->firstOrFail();
        $componente = $this->painel();

        $componente->call('alternarAtiva', $ibge->id);
        $this->assertFalse($ibge->fresh()->ativa);
        $componente->assertSee('Pausada');

        $componente->call('alternarAtiva', $ibge->id);
        $this->assertTrue($ibge->fresh()->ativa);

        $this->assertDatabaseHas('audit_logs', ['evento' => 'integracao_pausada']);
        $this->assertDatabaseHas('audit_logs', ['evento' => 'integracao_retomada']);
    }

    public function test_testar_conexao_mostra_resultado_para_chave_recusada_e_aceita(): void
    {
        $this->transparencia()->update(['config' => ['chave_api' => self::CHAVE]]);
        $id = $this->transparencia()->id;

        Http::fakeSequence('api.portaldatransparencia.gov.br/*')->push('', 403)->push([['quantidadeBeneficiados' => 1]], 200);

        $this->painel()
            ->call('testar', $id)
            ->assertSee('Teste falhou:')
            ->assertSee('recusou a chave')
            ->call('testar', $id)
            ->assertSee('Teste ok:')
            ->assertSee('Chave aceita');

        Http::assertSent(fn ($request): bool => $request->hasHeader('chave-api-dados', self::CHAVE));
    }

    public function test_testar_sem_chave_nao_faz_requisicao(): void
    {
        Http::fake();

        $this->painel()->call('testar', $this->transparencia()->id)->assertSee('ainda não foi informada');

        Http::assertNothingSent();
    }

    public function test_testes_seguidos_sao_limitados(): void
    {
        Http::fake(['*' => Http::response([['id' => 1]])]);
        $ibge = Integracao::where('fonte', 'ibge')->firstOrFail();
        $componente = $this->painel();

        foreach (range(1, 6) as $i) {
            $componente->call('testar', $ibge->id);
        }

        $componente->call('testar', $ibge->id)->assertSee('Muitos testes seguidos');
    }

    public function test_atualizar_agora_enfileira_com_o_periodo_escolhido_e_o_usuario(): void
    {
        Queue::fake();
        $ibge = Integracao::where('fonte', 'ibge')->firstOrFail();

        $this->painel()
            ->call('abrirAtualizacao', $ibge->id)
            ->set('periodo', '36')
            ->call('atualizarAgora')
            ->assertHasNoErrors()
            ->assertSet('janela', null);

        Queue::assertPushed(IngerirFonte::class, fn (IngerirFonte $job): bool => $job->integracaoId === $ibge->id
            && $job->meses === 36
            && $job->userId === $this->admin->id
            && $job->origem === OrigemExecucao::Manual);

        $this->assertSame(StatusIntegracao::NaFila, $ibge->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['evento' => 'integracao_execucao_forcada', 'user_id' => $this->admin->id]);
    }

    public function test_clique_repetido_nao_enfileira_duas_vezes(): void
    {
        Queue::fake();
        $ibge = Integracao::where('fonte', 'ibge')->firstOrFail();
        $componente = $this->painel();

        $componente->call('abrirAtualizacao', $ibge->id)->call('atualizarAgora');
        $componente->call('abrirAtualizacao', $ibge->id)->call('atualizarAgora');

        Queue::assertPushed(IngerirFonte::class, 1);
    }

    public function test_atualizar_exige_configuracao_obrigatoria(): void
    {
        Queue::fake();

        $this->painel()
            ->call('abrirAtualizacao', $this->transparencia()->id)
            ->call('atualizarAgora')
            ->assertHasErrors('periodo');

        Queue::assertNothingPushed();
    }

    public function test_periodo_invalido_e_rejeitado(): void
    {
        Queue::fake();

        $this->painel()
            ->call('abrirAtualizacao', Integracao::where('fonte', 'ibge')->firstOrFail()->id)
            ->set('periodo', '99999')
            ->call('atualizarAgora')
            ->assertHasErrors('periodo');

        Queue::assertNothingPushed();
    }

    public function test_limite_de_atualizacoes_manuais_por_hora(): void
    {
        Queue::fake();
        $integracoes = Integracao::query()->get();
        $componente = $this->painel();

        for ($i = 0; $i < 10; $i++) {
            $alvo = $integracoes[$i % 3];
            $alvo->forceFill(['status' => StatusIntegracao::Nunca])->save();
            $componente->call('abrirAtualizacao', $alvo->id)->call('atualizarAgora');
        }

        $alvo = $integracoes[0];
        $alvo->forceFill(['status' => StatusIntegracao::Nunca])->save();

        $componente->call('abrirAtualizacao', $alvo->id)->call('atualizarAgora')->assertHasErrors('periodo');
    }

    public function test_o_identificador_da_janela_nao_pode_ser_adulterado_pelo_navegador(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->painel()->set('integracaoId', 999);
    }

    public function test_historico_mostra_execucoes_com_usuario_erro_e_avisos(): void
    {
        $ibge = Integracao::where('fonte', 'ibge')->firstOrFail();

        Ingestao::factory()->create(['integracao_id' => $ibge->id, 'user_id' => $this->admin->id, 'origem' => OrigemExecucao::Manual, 'linhas' => 1234, 'avisos' => ['Resende: sem dados']]);
        Ingestao::factory()->create(['integracao_id' => $ibge->id, 'status' => StatusIngestao::Erro, 'mensagem' => 'O SIDRA não respondeu', 'iniciada_em' => now()->subDay(), 'finalizada_em' => now()->subDay()]);

        $this->painel()
            ->call('abrirHistorico', $ibge->id)
            ->assertSee('Manual (tela)')
            ->assertSee($this->admin->name)
            ->assertSee('1.234 valores')
            ->assertSee('Resende: sem dados')
            ->assertSee('O SIDRA não respondeu');
    }

    public function test_erro_da_ultima_execucao_aparece_no_cartao(): void
    {
        Integracao::where('fonte', 'ibge')->firstOrFail()->update(['status' => StatusIntegracao::Erro, 'ultimo_erro' => 'O SIDRA não respondeu']);

        $this->painel()->assertSee('Falhou')->assertSee('Última execução falhou')->assertSee('O SIDRA não respondeu');
    }

    public function test_dados_anuais_do_ibge_mostram_so_o_ano_e_os_mensais_mostram_mes_e_ano(): void
    {
        Integracao::where('fonte', 'ibge')->firstOrFail()->update(['ultima_competencia' => 202612]);
        Integracao::where('fonte', 'egestor')->firstOrFail()->update(['ultima_competencia' => 202607]);

        $this->painel()->assertDontSee('dez/2026')->assertSee('2026')->assertSee('jul/2026');
    }

    public function test_execucao_em_andamento_mostra_barra_de_progresso_percentual_e_etapa(): void
    {
        $transparencia = Integracao::where('fonte', 'transparencia')->firstOrFail();
        $transparencia->update(['status' => StatusIntegracao::Executando, 'ativa' => true, 'config' => ['chave_api' => self::CHAVE]]);

        Ingestao::factory()->create([
            'integracao_id' => $transparencia->id,
            'status' => StatusIngestao::Executando,
            'iniciada_em' => now()->subMinutes(5),
            'finalizada_em' => null,
            'etapa' => 'Beneficiários por município e mês',
            'passos_total' => 1000,
            'passos_concluidos' => 250,
        ]);

        $this->painel()
            ->assertSee('Beneficiários por município e mês')
            ->assertSee('25%')
            ->assertSee('250 de 1.000')
            ->assertSee('restam cerca de 15 min 00 s')
            ->assertSeeHtml('aria-valuenow="25"');
    }

    public function test_execucao_na_fila_informa_que_aguarda_o_processador(): void
    {
        Integracao::where('fonte', 'ibge')->firstOrFail()->update(['status' => StatusIntegracao::NaFila]);

        $this->painel()->assertSee('Aguardando o processador de filas iniciar');
    }

    public function test_aviso_quando_a_fila_parece_parada(): void
    {
        $ibge = Integracao::where('fonte', 'ibge')->firstOrFail();
        $ibge->forceFill(['status' => StatusIntegracao::NaFila, 'updated_at' => now()->subMinutes(10)])->saveQuietly();

        $this->painel()->assertSee('queue:work');
    }

    public function test_a_tela_aparece_no_menu_apenas_para_administradores(): void
    {
        $this->actingAs($this->admin)->get(route('painel'))->assertSee('Administração');
        $this->actingAs(User::factory()->gestor()->create())->get(route('painel'))->assertDontSee('Administração');
    }
}
