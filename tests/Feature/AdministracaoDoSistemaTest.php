<?php

namespace Tests\Feature;

use App\Administracao\Fila;
use App\Administracao\RotulosDeAuditoria;
use App\Administracao\VerificacaoDeSaude;
use App\Administracao\VerificadorDeSaude;
use App\Http\Controllers\Admin\ExportarAuditoriaController;
use App\Livewire\Admin\Auditoria;
use App\Livewire\Admin\Inicio;
use App\Livewire\Admin\Sistema;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\VersaoDosDados;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AdministracaoDoSistemaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        $this->admin = User::factory()->admin()->comDoisFatores()->create();
        Cache::forget(VerificadorDeSaude::CHAVE_DO_BATIMENTO);
        Cache::forget(VerificadorDeSaude::CHAVE_DE_INDICES_DESATUALIZADOS);
    }

    private function como(string $componente): Testable
    {
        return Livewire::actingAs($this->admin)->test($componente);
    }

    private function falhaNaFila(string $uuid = 'abc-123', string $trabalho = 'App\\Jobs\\IngerirFonte'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $trabalho, 'uuid' => $uuid]),
            'exception' => "RuntimeException: Deu ruim ao falar com a fonte\n#0 pilha de chamadas",
            'failed_at' => now(),
        ]);
    }

    private function verificacao(string $nome): ?VerificacaoDeSaude
    {
        return collect(app(VerificadorDeSaude::class)->executar())->firstWhere('nome', $nome);
    }

    // ------------------------------------------------------------------ Saúde

    public function test_agendador_sem_batimento_pede_atencao_e_com_batimento_recente_fica_ok(): void
    {
        $this->assertSame(VerificacaoDeSaude::ATENCAO, $this->verificacao('Agendador de tarefas')->estado);

        Cache::put(VerificadorDeSaude::CHAVE_DO_BATIMENTO, now()->getTimestamp());
        $this->assertSame(VerificacaoDeSaude::OK, $this->verificacao('Agendador de tarefas')->estado);

        Cache::put(VerificadorDeSaude::CHAVE_DO_BATIMENTO, now()->subMinutes(30)->getTimestamp());
        $this->assertSame(VerificacaoDeSaude::ERRO, $this->verificacao('Agendador de tarefas')->estado);
    }

    public function test_o_agendador_registra_o_proprio_batimento(): void
    {
        Artisan::call('schedule:run');

        $this->assertNotNull(Cache::get(VerificadorDeSaude::CHAVE_DO_BATIMENTO));
    }

    public function test_fila_com_trabalho_antigo_esperando_indica_processador_parado(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subHour()->getTimestamp(), 'created_at' => now()->subHour()->getTimestamp()]);

        $this->assertSame(VerificacaoDeSaude::ERRO, $this->verificacao('Processador de filas')->estado);
    }

    public function test_falhas_na_fila_pedem_atencao(): void
    {
        $this->assertSame(VerificacaoDeSaude::OK, $this->verificacao('Processador de filas')->estado);

        $this->falhaNaFila();

        $this->assertSame(VerificacaoDeSaude::ATENCAO, $this->verificacao('Processador de filas')->estado);
    }

    public function test_administradores_e_contas_sao_conferidos(): void
    {
        $this->assertSame(VerificacaoDeSaude::ATENCAO, $this->verificacao('Administradores')->estado, 'um único administrador');

        User::factory()->admin()->create();
        $this->assertSame(VerificacaoDeSaude::OK, $this->verificacao('Administradores')->estado);
        $this->assertSame(VerificacaoDeSaude::ATENCAO, $this->verificacao('Verificação em duas etapas dos administradores')->estado);

        User::factory()->comSenhaTemporaria()->create(['created_at' => now()->subDays(10)]);
        $this->assertSame(VerificacaoDeSaude::ATENCAO, $this->verificacao('Senhas temporárias antigas')->estado);

        $this->assertNull($this->verificacao('Contas sem uso'));
        User::factory()->create(['ultimo_acesso_em' => now()->subDays(120)]);
        $this->assertSame(VerificacaoDeSaude::ATENCAO, $this->verificacao('Contas sem uso')->estado);
    }

    public function test_resumo_conta_cada_estado(): void
    {
        $resumo = app(VerificadorDeSaude::class)->resumo();

        $this->assertSame(count(app(VerificadorDeSaude::class)->executar()), array_sum($resumo));
        $this->assertSame(['ok', 'atencao', 'erro'], array_keys($resumo));
    }

    public function test_formatadores_de_tamanho_e_tempo(): void
    {
        $this->assertSame('512 B', VerificadorDeSaude::bytes(512));
        $this->assertSame('1,5 KB', VerificadorDeSaude::bytes(1536));
        $this->assertSame('2,0 GB', VerificadorDeSaude::bytes(2 * 1024 ** 3));
        $this->assertSame('45 s', VerificadorDeSaude::duracao(45));
        $this->assertSame('10 min', VerificadorDeSaude::duracao(600));
        $this->assertSame('3 h', VerificadorDeSaude::duracao(3 * 3600));
        $this->assertSame('5 dias', VerificadorDeSaude::duracao(5 * 86400));
    }

    // ------------------------------------------------------------------ Tela Sistema

    public function test_tela_do_sistema_lista_verificacoes_fila_e_ambiente(): void
    {
        $this->falhaNaFila();

        $this->como(Sistema::class)
            ->assertSee(['Ambiente e segurança', 'Infraestrutura', 'Agendador de tarefas', 'Fila de trabalhos', 'IngerirFonte', 'Deu ruim ao falar com a fonte', 'Informações do ambiente'])
            ->assertDontSee('pilha de chamadas');
    }

    public function test_reenviar_e_descartar_trabalhos_que_falharam(): void
    {
        $this->falhaNaFila('uuid-um');
        $this->falhaNaFila('uuid-dois');
        $tela = $this->como(Sistema::class);

        $tela->call('descartarFalha', 'uuid-um');
        $this->assertSame(1, app(Fila::class)->totalDeFalhas());
        $this->assertTrue(AuditLog::where('evento', 'sistema_fila_descartada')->exists());

        $this->assertTrue(app(Fila::class)->reenviar('uuid-dois'));
        $this->assertSame(0, app(Fila::class)->totalDeFalhas());
        $this->assertSame(1, DB::table('jobs')->count());

        $tela->call('reenviarFalha', 'nao-existe')->assertSee('não está mais na lista');
    }

    public function test_descartar_todas_as_falhas(): void
    {
        $this->falhaNaFila('a');
        $this->falhaNaFila('b');

        $this->como(Sistema::class)->call('descartarTodasAsFalhas');

        $this->assertSame(0, app(Fila::class)->totalDeFalhas());
        $this->assertSame(2, AuditLog::where('evento', 'sistema_fila_descartada')->firstOrFail()->dados['quantidade']);
    }

    public function test_renovar_o_cache_muda_a_versao_dos_dados(): void
    {
        $antes = VersaoDosDados::atual();

        $this->como(Sistema::class)->call('renovarCache')->assertSee('Cache dos visuais renovado');

        $this->assertNotSame($antes, VersaoDosDados::atual());
        $this->assertTrue(AuditLog::where('evento', 'sistema_cache_renovado')->exists());
    }

    public function test_recalcular_medianas_registra_a_acao(): void
    {
        $this->como(Sistema::class)->call('recalcularBenchmarks')->assertSee('Medianas de comparação refeitas');

        $this->assertTrue(AuditLog::where('evento', 'sistema_benchmarks_recalculados')->exists());
    }

    // ------------------------------------------------------------------ Visão geral

    public function test_visao_geral_lista_pendencias_com_link_para_resolver(): void
    {
        $this->como(Inicio::class)
            ->assertSee(['Pendências', 'Agendador de tarefas', 'Atividade recente'])
            ->assertSee(route('admin.sistema'), false);
    }

    public function test_visao_geral_mostra_que_nao_ha_pendencias_quando_tudo_esta_em_ordem(): void
    {
        $this->assertGreaterThan(0, count(app(VerificadorDeSaude::class)->executar()));

        $this->como(Inicio::class)->assertViewHas('pendencias', fn ($pendencias) => $pendencias->every(fn (array $item) => $item['verificacao']->estado !== VerificacaoDeSaude::OK));
    }

    // ------------------------------------------------------------------ Auditoria

    public function test_auditoria_lista_filtra_e_mostra_detalhes(): void
    {
        $outro = User::factory()->create(['name' => 'Helena Souza', 'email' => 'helena@exemplo.com']);
        $entrou = AuditLog::factory()->create(['user_id' => $outro->id, 'evento' => 'login', 'ip' => '10.0.0.7']);
        $criou = AuditLog::factory()->create(['user_id' => $this->admin->id, 'evento' => 'usuario_criado', 'dados' => ['email' => 'novo@exemplo.com', 'perfil' => 'gestor']]);
        $recusado = AuditLog::factory()->create(['user_id' => null, 'evento' => 'login_falhou', 'dados' => ['email' => 'intruso@exemplo.com']]);

        $tela = $this->como(Auditoria::class);
        $tela->assertSee(['Entrou no sistema', 'Criou um usuário', 'Tentativa de acesso recusada', 'novo@exemplo.com']);
        $this->assertSame([$entrou->id, $criou->id, $recusado->id], $this->registrosVisiveis($tela));

        $tela->set('grupo', 'usuarios');
        $this->assertSame([$criou->id], $this->registrosVisiveis($tela));

        $tela->set('grupo', '')->set('evento', 'login');
        $this->assertSame([$entrou->id], $this->registrosVisiveis($tela));

        $tela->set('evento', '')->set('busca', 'helena@');
        $this->assertSame([$entrou->id], $this->registrosVisiveis($tela));

        $tela->set('busca', '10.0.0.7');
        $this->assertSame([$entrou->id], $this->registrosVisiveis($tela));

        $tela->set('busca', 'intruso');
        $this->assertSame([$recusado->id], $this->registrosVisiveis($tela));

        $tela->call('limparFiltros')->assertSet('busca', '');
        $this->assertCount(3, $this->registrosVisiveis($tela));
    }

    public function test_auditoria_filtra_por_periodo(): void
    {
        $antigo = AuditLog::factory()->create(['evento' => 'logout']);
        $antigo->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
        $novo = AuditLog::factory()->create(['evento' => 'login']);

        $tela = $this->como(Auditoria::class);

        $tela->set('de', now()->subDays(5)->format('Y-m-d'));
        $this->assertSame([$novo->id], $this->registrosVisiveis($tela));

        $tela->set('de', '')->set('ate', now()->subDays(30)->format('Y-m-d'));
        $this->assertSame([$antigo->id], $this->registrosVisiveis($tela));

        $tela->set('ate', 'data-invalida');
        $this->assertCount(2, $this->registrosVisiveis($tela));
    }

    /**
     * Ids dos registros exibidos na lista, em ordem alfabética de id (as opções dos filtros também citam os nomes dos eventos).
     *
     * @return list<int>
     */
    private function registrosVisiveis(Testable $tela): array
    {
        preg_match_all('/wire:key="registro-(\d+)"/', $tela->html(), $encontrados);

        $ids = array_map('intval', $encontrados[1]);
        sort($ids);

        return $ids;
    }

    public function test_todo_evento_conhecido_tem_rotulo_e_grupo_validos(): void
    {
        foreach (RotulosDeAuditoria::todos() as $codigo => $definicao) {
            $this->assertArrayHasKey($definicao['grupo'], RotulosDeAuditoria::GRUPOS, $codigo);
            $this->assertNotSame($codigo, RotulosDeAuditoria::rotulo($codigo));
        }

        $this->assertSame('evento_novo', RotulosDeAuditoria::rotulo('evento_novo'));
        $this->assertSame('Outros', RotulosDeAuditoria::grupo('evento_novo'));
    }

    public function test_exportacao_gera_csv_com_os_filtros_e_protege_contra_formulas(): void
    {
        $alvo = User::factory()->create(['name' => '=HYPERLINK("http://mal.example","clique")', 'email' => 'alvo@exemplo.com']);
        AuditLog::factory()->create(['user_id' => $alvo->id, 'evento' => 'login', 'ip' => '10.1.1.1']);
        AuditLog::factory()->create(['user_id' => $this->admin->id, 'evento' => 'usuario_criado', 'dados' => ['email' => 'x@exemplo.com']]);

        $resposta = $this->actingAs($this->admin)->get(route('admin.auditoria.exportar', ['grupo' => 'acesso']));

        $resposta->assertOk()->assertDownload();
        $conteudo = $resposta->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $conteudo);
        $this->assertStringContainsString('Entrou no sistema', $conteudo);
        $this->assertStringNotContainsString('Criou um usuário', $conteudo);
        $this->assertStringContainsString("'=HYPERLINK", $conteudo);
        $this->assertStringNotContainsString(';=HYPERLINK', $conteudo);
        $this->assertTrue(AuditLog::where('evento', 'auditoria_exportada')->exists());
    }

    public function test_exportacao_recusa_filtros_invalidos(): void
    {
        $this->actingAs($this->admin)->get(route('admin.auditoria.exportar', ['de' => 'ontem']))->assertSessionHasErrors('de');
    }

    public function test_protecao_de_planilha_so_altera_o_que_comeca_com_caractere_de_formula(): void
    {
        $this->assertSame("'=1+1", ExportarAuditoriaController::protegerPlanilha('=1+1'));
        $this->assertSame("'+55", ExportarAuditoriaController::protegerPlanilha('+55'));
        $this->assertSame("'-1", ExportarAuditoriaController::protegerPlanilha('-1'));
        $this->assertSame("'@soma", ExportarAuditoriaController::protegerPlanilha('@soma'));
        $this->assertSame('texto normal', ExportarAuditoriaController::protegerPlanilha('texto normal'));
        $this->assertSame('', ExportarAuditoriaController::protegerPlanilha(''));
    }

    public function test_registros_alem_do_prazo_de_retencao_sao_removidos(): void
    {
        config(['aps.auditoria.retencao_dias' => 90]);

        $velho = AuditLog::factory()->create(['evento' => 'logout']);
        $velho->forceFill(['created_at' => now()->subDays(120)])->saveQuietly();
        $recente = AuditLog::factory()->create(['evento' => 'login']);
        $limite = AuditLog::factory()->create(['evento' => 'login']);
        $limite->forceFill(['created_at' => now()->subDays(60)])->saveQuietly();

        Artisan::call('model:prune', ['--model' => [AuditLog::class]]);

        $this->assertFalse(AuditLog::whereKey($velho->id)->exists());
        $this->assertTrue(AuditLog::whereKey($recente->id)->exists());
        $this->assertTrue(AuditLog::whereKey($limite->id)->exists());
    }

    public function test_a_retencao_nunca_fica_abaixo_de_30_dias(): void
    {
        config(['aps.auditoria.retencao_dias' => 1]);

        $registro = AuditLog::factory()->create();
        $registro->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        Artisan::call('model:prune', ['--model' => [AuditLog::class]]);

        $this->assertTrue(AuditLog::whereKey($registro->id)->exists());
    }

    public function test_telas_do_sistema_so_para_administradores(): void
    {
        foreach ([Sistema::class, Inicio::class, Auditoria::class] as $componente) {
            Livewire::actingAs(User::factory()->gestor()->create())->test($componente)->assertForbidden();
        }
    }
}
