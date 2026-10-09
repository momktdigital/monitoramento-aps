<?php

namespace Tests\Feature;

use App\Administracao\VerificadorDeSaude;
use App\Domain\Scoring\CalculadorDeIndices;
use App\Jobs\RecalcularIndices;
use App\Jobs\SincronizarMunicipiosJob;
use App\Livewire\Admin\Indicadores;
use App\Livewire\Admin\Indices;
use App\Livewire\Admin\Municipios;
use App\Models\AuditLog;
use App\Models\Indicador;
use App\Models\Metodologia;
use App\Models\Municipio;
use App\Models\User;
use App\Support\VersaoDosDados;
use Database\Seeders\IndicadorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AdministracaoDeDadosTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'Senha-Forte-123!';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->comDoisFatores()->create(['password' => self::SENHA]);

        foreach (['admin-confirmar-senha:'.$this->admin->id, 'admin-sincronizar-municipios', 'admin-recalcular-indices'] as $chave) {
            RateLimiter::clear($chave);
        }
        Cache::forget(VerificadorDeSaude::CHAVE_DE_INDICES_DESATUALIZADOS);
        Cache::forget(RecalcularIndices::CHAVE_DO_ANDAMENTO);
    }

    private function como(string $componente): Testable
    {
        return Livewire::actingAs($this->admin)->test($componente);
    }

    // ------------------------------------------------------------------ Municípios

    public function test_ativar_e_desativar_municipio_registra_auditoria_e_marca_indices_desatualizados(): void
    {
        $municipio = Municipio::factory()->create(['nome' => 'Valença']);
        $versao = VersaoDosDados::atual();

        $this->como(Municipios::class)->assertSee('Valença')->call('alternarAtivo', $municipio->id)->assertSee('só entra nos índices');

        $this->assertFalse($municipio->fresh()->ativo);
        $this->assertNotNull(Cache::get(VerificadorDeSaude::CHAVE_DE_INDICES_DESATUALIZADOS));
        $this->assertNotSame($versao, VersaoDosDados::atual());
        $this->assertSame(false, AuditLog::where('evento', 'municipio_ativo_alterado')->firstOrFail()->dados['ativo']);
    }

    public function test_marcar_piloto_nao_desatualiza_os_indices(): void
    {
        $municipio = Municipio::factory()->create();

        $this->como(Municipios::class)->call('alternarPiloto', $municipio->id);

        $this->assertTrue($municipio->fresh()->piloto);
        $this->assertNull(Cache::get(VerificadorDeSaude::CHAVE_DE_INDICES_DESATUALIZADOS));
        $this->assertTrue(AuditLog::where('evento', 'municipio_piloto_alterado')->exists());
    }

    public function test_piloto_em_lote_por_regiao_de_saude(): void
    {
        Municipio::factory()->count(3)->create(['regiao_saude_nome' => 'MEDIO PARAIBA']);
        $fora = Municipio::factory()->create(['regiao_saude_nome' => 'OUTRA REGIAO']);

        $tela = $this->como(Municipios::class)->set('regiao', 'MEDIO PARAIBA')->call('definirPilotoDaRegiao', true);

        $this->assertSame(3, Municipio::piloto()->count());
        $this->assertFalse($fora->fresh()->piloto);
        $this->assertSame(3, AuditLog::where('evento', 'municipios_piloto_em_lote')->firstOrFail()->dados['quantidade']);

        $tela->call('definirPilotoDaRegiao', false);
        $this->assertSame(0, Municipio::piloto()->count());
    }

    public function test_lote_sem_regiao_escolhida_nao_faz_nada(): void
    {
        Municipio::factory()->count(2)->create();

        $this->como(Municipios::class)->call('definirPilotoDaRegiao', true);

        $this->assertSame(0, Municipio::piloto()->count());
    }

    public function test_filtros_de_municipios(): void
    {
        Municipio::factory()->create(['nome' => 'Valença', 'nome_busca' => 'valenca']);
        Municipio::factory()->create(['nome' => 'Resende', 'nome_busca' => 'resende', 'ativo' => false]);
        Municipio::factory()->piloto()->create(['nome' => 'Vassouras', 'nome_busca' => 'vassouras']);

        $this->como(Municipios::class)
            ->set('busca', 'valenca')->assertSee('Valença')->assertDontSee('Resende')
            ->set('busca', '')->set('situacao', 'inativos')->assertSee('Resende')->assertDontSee('Valença')
            ->set('situacao', 'piloto')->assertSee('Vassouras')->assertDontSee('Resende');
    }

    public function test_sincronizacao_vai_para_a_fila_como_trabalho_unico(): void
    {
        Queue::fake();
        $tela = $this->como(Municipios::class);

        $tela->call('sincronizar')->call('sincronizar');

        Queue::assertPushed(SincronizarMunicipiosJob::class, 1);
        $this->assertSame(2, AuditLog::where('evento', 'municipios_sincronizacao_pedida')->count());
    }

    public function test_sincronizacao_tem_limite_de_pedidos_por_hora(): void
    {
        Queue::fake();

        foreach (range(1, 3) as $_) {
            RateLimiter::hit('admin-sincronizar-municipios', 3600);
        }

        $this->como(Municipios::class)->call('sincronizar')->assertSee('várias vezes');

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------ Indicadores

    public function test_ativar_e_ocultar_indicadores(): void
    {
        $indicador = Indicador::factory()->create(['codigo' => 'cobertura_esf']);

        $this->como(Indicadores::class)->call('alternarAtivo', $indicador->id)->assertSee('faz parte do cálculo dos índices');

        $this->assertFalse($indicador->fresh()->ativo);
        $this->assertNotNull(Cache::get(VerificadorDeSaude::CHAVE_DE_INDICES_DESATUALIZADOS));

        $this->como(Indicadores::class)->call('alternarVisivel', $indicador->id);

        $this->assertFalse($indicador->fresh()->visivel);
        $this->assertTrue(AuditLog::where('evento', 'indicador_visivel_alterado')->exists());
    }

    public function test_edita_nome_e_textos_de_ajuda(): void
    {
        $indicador = Indicador::factory()->create(['nome' => 'Antigo']);
        $versao = VersaoDosDados::atual();

        $this->como(Indicadores::class)
            ->call('editar', $indicador->id)
            ->assertSet('nome', 'Antigo')
            ->set('nome', 'Cobertura de equipes')
            ->set('oQueE', 'Texto um.')
            ->set('comoCalcula', 'Texto dois.')
            ->set('paraQueServe', 'Texto três.')
            ->set('comoInterpretar', 'Texto quatro.')
            ->call('salvar')
            ->assertHasNoErrors()
            ->assertSet('editandoId', null);

        $indicador->refresh();
        $this->assertSame('Cobertura de equipes', $indicador->nome);
        $this->assertSame('Texto quatro.', $indicador->como_interpretar);
        $this->assertNotSame($versao, VersaoDosDados::atual());
        $this->assertContains('nome', array_keys(AuditLog::where('evento', 'indicador_atualizado')->firstOrFail()->dados['alteracoes']));
    }

    public function test_textos_do_indicador_sao_obrigatorios_e_limitados(): void
    {
        $indicador = Indicador::factory()->create();

        $this->como(Indicadores::class)
            ->call('editar', $indicador->id)
            ->set('nome', '')
            ->set('oQueE', str_repeat('a', 1501))
            ->call('salvar')
            ->assertHasErrors(['nome', 'oQueE']);
    }

    public function test_restaura_os_textos_do_catalogo_original(): void
    {
        $this->seed(IndicadorSeeder::class);
        $indicador = Indicador::where('codigo', 'cobertura_esf')->firstOrFail();
        $original = $indicador->o_que_e;
        $indicador->update(['o_que_e' => 'Texto alterado à mão', 'nome' => 'Nome alterado']);

        $this->como(Indicadores::class)->call('editar', $indicador->id)->call('restaurarTextos')->assertSet('oQueE', $original);

        $this->assertSame($original, $indicador->fresh()->o_que_e);
        $this->assertNotSame('Nome alterado', $indicador->fresh()->nome);
        $this->assertTrue(AuditLog::where('evento', 'indicador_textos_restaurados')->exists());
    }

    public function test_indicador_fora_do_catalogo_nao_tem_o_que_restaurar(): void
    {
        $indicador = Indicador::factory()->create(['codigo' => 'indicador-proprio']);

        $this->como(Indicadores::class)->call('editar', $indicador->id)->call('restaurarTextos')->assertHasErrors(['nome']);
    }

    // ------------------------------------------------------------------ Índices e metodologia

    public function test_ajustar_pesos_cria_nova_versao_do_painel_que_nao_e_sobrescrita_pelo_arquivo(): void
    {
        $original = Metodologia::sincronizar();

        $this->como(Indices::class)
            ->call('editarPesos')
            ->set('pilares.idaps.estrutura', 50)
            ->set('componentes.idaps.estrutura.cobertura_esf', 5)
            ->set('senhaAtual', self::SENHA)
            ->call('salvarPesos')
            ->assertHasNoErrors()
            ->assertSet('janela', null);

        $nova = Metodologia::ativa()->firstOrFail();
        $this->assertSame($original->versao + 1, $nova->versao);
        $this->assertTrue($nova->veioDoPainel());
        $this->assertEquals(50, $nova->configuracao['idaps']['pilares']['estrutura']['peso']);
        $this->assertEquals(5, $nova->configuracao['idaps']['pilares']['estrutura']['componentes']['cobertura_esf']['peso']);
        $this->assertEquals(35, $original->fresh()->configuracao['idaps']['pilares']['estrutura']['peso'], 'a versão anterior continua intacta');

        $this->assertTrue(Metodologia::sincronizar()->is($nova), 'o arquivo não derruba o ajuste feito no painel');
        $this->assertSame(1, AuditLog::where('evento', 'metodologia_pesos_alterados')->count());
    }

    public function test_ajustar_pesos_exige_a_senha(): void
    {
        $original = Metodologia::sincronizar();

        $this->como(Indices::class)->call('editarPesos')->set('pilares.idaps.estrutura', 50)->call('salvarPesos')->assertHasErrors(['senhaAtual']);
        $this->como(Indices::class)->call('editarPesos')->set('pilares.idaps.estrutura', 50)->set('senhaAtual', 'errada')->call('salvarPesos')->assertHasErrors(['senhaAtual']);

        $this->assertTrue(Metodologia::ativa()->firstOrFail()->is($original));
    }

    public function test_pesos_invalidos_sao_recusados(): void
    {
        Metodologia::sincronizar();

        $this->como(Indices::class)->call('editarPesos')->set('pilares.idaps.estrutura', -1)->set('senhaAtual', self::SENHA)->call('salvarPesos')->assertHasErrors(['pilares.idaps.estrutura']);
        $this->como(Indices::class)->call('editarPesos')->set('pilares.idaps.estrutura', 'abc')->set('senhaAtual', self::SENHA)->call('salvarPesos')->assertHasErrors(['pilares.idaps.estrutura']);
        $this->como(Indices::class)->call('editarPesos')->set('pilares.idaps.estrutura', 1000)->set('senhaAtual', self::SENHA)->call('salvarPesos')->assertHasErrors(['pilares.idaps.estrutura']);

        $this->assertSame(1, Metodologia::count());
    }

    public function test_pilar_com_peso_precisa_de_algum_indicador_com_peso(): void
    {
        Metodologia::sincronizar();
        $tela = $this->como(Indices::class)->call('editarPesos');

        foreach (array_keys($tela->get('componentes')['ina']['demografia']) as $codigo) {
            $tela->set("componentes.ina.demografia.{$codigo}", 0);
        }

        $tela->set('senhaAtual', self::SENHA)->call('salvarPesos')->assertHasErrors(['pilares.ina.demografia']);
    }

    public function test_nao_zera_todos_os_pilares_de_um_indice(): void
    {
        Metodologia::sincronizar();
        $tela = $this->como(Indices::class)->call('editarPesos');

        foreach (array_keys($tela->get('pilares')['ina']) as $pilar) {
            $tela->set("pilares.ina.{$pilar}", 0);
        }

        $tela->set('senhaAtual', self::SENHA)->call('salvarPesos')->assertHasErrors(['pilares.ina']);
    }

    public function test_salvar_sem_mudar_nada_nao_cria_versao(): void
    {
        Metodologia::sincronizar();

        $this->como(Indices::class)->call('editarPesos')->set('senhaAtual', self::SENHA)->call('salvarPesos')->assertSee('nada foi alterado');

        $this->assertSame(1, Metodologia::count());
    }

    public function test_voltar_ao_arquivo_abandona_o_ajuste_do_painel(): void
    {
        $arquivo = Metodologia::sincronizar();
        $config = $arquivo->configuracao;
        $config['idaps']['pilares']['estrutura']['peso'] = 60;
        $painel = Metodologia::ativarDoPainel($config);

        $this->como(Indices::class)
            ->assertSee('Voltar ao arquivo')
            ->call('pedirVoltaAoArquivo')
            ->set('senhaAtual', 'errada')->call('voltarAoArquivo')->assertHasErrors(['senhaAtual'])
            ->set('senhaAtual', self::SENHA)->call('voltarAoArquivo')->assertHasNoErrors();

        $ativa = Metodologia::ativa()->firstOrFail();
        $this->assertTrue($ativa->is($arquivo));
        $this->assertFalse($painel->fresh()->ativa);
        $this->assertTrue(AuditLog::where('evento', 'metodologia_arquivo_restaurado')->exists());
    }

    public function test_recalcular_vai_para_a_fila_uma_vez_enquanto_estiver_em_andamento(): void
    {
        Queue::fake();
        Cache::put(VerificadorDeSaude::CHAVE_DE_INDICES_DESATUALIZADOS, now()->getTimestamp());

        $tela = $this->como(Indices::class)->assertSee('Recalcular agora')->call('recalcular')->assertSee('Recálculo na fila');
        $tela->call('recalcular')->assertSee('Já existe um recálculo em andamento');

        Queue::assertPushed(RecalcularIndices::class, 1);
        $this->assertSame('na_fila', RecalcularIndices::andamento()['estado']);
        $this->assertTrue(AuditLog::where('evento', 'indices_recalculados')->exists());
    }

    public function test_o_recalculo_conclui_e_limpa_o_aviso_de_indices_desatualizados(): void
    {
        Cache::put(VerificadorDeSaude::CHAVE_DE_INDICES_DESATUALIZADOS, now()->getTimestamp());

        (new RecalcularIndices)->handle(app(CalculadorDeIndices::class));

        $this->assertSame('concluido', RecalcularIndices::andamento()['estado']);
        $this->assertNull(Cache::get(VerificadorDeSaude::CHAVE_DE_INDICES_DESATUALIZADOS));

        (new RecalcularIndices)->failed(new \RuntimeException('falhou feio'));

        $this->assertSame('erro', RecalcularIndices::andamento()['estado']);
        $this->como(Indices::class)->assertSee('O último recálculo falhou');
    }

    public function test_telas_de_dados_so_para_administradores(): void
    {
        foreach ([Municipios::class, Indicadores::class, Indices::class] as $componente) {
            Livewire::actingAs(User::factory()->gestor()->create())->test($componente)->assertForbidden();
        }
    }
}
