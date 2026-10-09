<?php

namespace Tests\Feature;

use App\Enums\TipoDeVisual;
use App\Models\Indicador;
use App\Models\User;
use App\Widgets\CatalogoDeVisuais;
use App\Widgets\PainelPadrao;
use Database\Seeders\IndicadorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O painel padrão montado sobre o catálogo de verdade (seeder), para garantir que a configuração de áreas
 * em `config/painel.php` cobre os indicadores e não tem erros de digitação.
 */
class PainelPadraoTest extends TestCase
{
    use RefreshDatabase;

    private PainelPadrao $padrao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IndicadorSeeder::class);
        $this->padrao = app(PainelPadrao::class);
    }

    public function test_todo_indicador_citado_na_configuracao_existe_no_catalogo(): void
    {
        $codigos = Indicador::pluck('codigo')->all();

        foreach (config('painel.areas') as $chave => $area) {
            foreach ($area['indicadores'] as $codigo) {
                $this->assertContains($codigo, $codigos, "área {$chave}: indicador {$codigo} não existe no catálogo");
            }

            foreach ($area['inicio'] ?? [] as [$tipo, $codigo]) {
                $this->assertNotNull(TipoDeVisual::tryFrom($tipo), "área {$chave}: tipo {$tipo} inválido");
                $this->assertContains($codigo, $codigos, "área {$chave}: indicador {$codigo} não existe no catálogo");
            }
        }
    }

    public function test_nenhum_indicador_aparece_em_duas_areas_da_configuracao(): void
    {
        $vistos = [];

        foreach (config('painel.areas') as $chave => $area) {
            foreach ($area['indicadores'] as $codigo) {
                $this->assertArrayNotHasKey($codigo, $vistos, "{$codigo} está nas áreas {$chave} e ".($vistos[$codigo] ?? '?'));
                $vistos[$codigo] = $chave;
            }
        }
    }

    public function test_todo_indicador_ativo_e_visivel_do_catalogo_tem_lugar_em_alguma_area_e_nada_cai_em_outros(): void
    {
        $modelos = $this->padrao->modelos();
        $presentes = collect($modelos)->flatMap(fn (array $m) => array_map(fn (array $v): string => $v[1], $m['visuais']))->unique()->all();

        foreach (Indicador::ativo()->visivel()->pluck('codigo') as $codigo) {
            $this->assertContains($codigo, $presentes, "{$codigo} não aparece em nenhuma área do painel padrão");
        }

        $this->assertNotContains('outros', array_column($modelos, 'chave'), 'inclua o indicador novo em uma área de config/painel.php');
    }

    public function test_cada_indicador_ativo_recebe_destaque_evolucao_ranking_e_mapa(): void
    {
        $visuais = collect($this->padrao->modelos())->flatMap(fn (array $m) => $m['visuais'])->groupBy(fn (array $v): string => $v[1]);

        foreach (Indicador::ativo()->visivel()->pluck('codigo') as $codigo) {
            $tipos = $visuais[$codigo]->map(fn (array $v): string => $v[0]->value)->sort()->values()->all();
            $esperados = $codigo === TipoDeVisual::INDICADOR_DA_MATRIZ ? ['destaque', 'evolucao', 'mapa', 'matriz', 'ranking'] : ['destaque', 'evolucao', 'mapa', 'ranking'];

            $this->assertSame($esperados, $tipos, $codigo);
        }
    }

    public function test_a_visao_geral_vem_primeiro_e_abre_com_a_matriz(): void
    {
        $modelos = $this->padrao->modelos();

        $this->assertSame('visao_geral', $modelos[0]['chave']);
        $this->assertSame([TipoDeVisual::Matriz, 'indice_prioridade'], $modelos[0]['visuais'][0]);
    }

    public function test_indicadores_ainda_inativos_ficam_de_fora_e_entram_quando_ativados(): void
    {
        $chaves = fn () => array_column(app(PainelPadrao::class)->modelos(), 'chave');

        $this->assertNotContains('cronicos', $chaves(), 'hipertensão e diabetes ainda não têm fonte');

        Indicador::where('codigo', 'hipertensao_acompanhada')->update(['ativo' => true]);

        $this->assertContains('cronicos', array_column((new PainelPadrao(new CatalogoDeVisuais))->modelos(), 'chave'));
    }

    public function test_indicador_oculto_nunca_entra(): void
    {
        $presentes = collect($this->padrao->modelos())->flatMap(fn (array $m) => array_map(fn (array $v): string => $v[1], $m['visuais']))->all();

        $this->assertNotContains('icsap_internacoes_mes', $presentes);
        $this->assertNotContains('mortalidade_evitavel', $presentes);
    }

    public function test_o_primeiro_painel_do_usuario_tem_todas_as_areas_e_a_primeira_e_a_inicial(): void
    {
        $usuario = User::factory()->gestor()->create();

        $this->assertTrue($this->padrao->garantirPara($usuario));
        $this->assertFalse($this->padrao->garantirPara($usuario), 'não recria');

        $areas = $usuario->areas()->get();

        $this->assertCount(count($this->padrao->modelos()), $areas);
        $this->assertTrue($areas->first()->padrao);
        $this->assertSame(1, $areas->where('padrao', true)->count());
        $this->assertSame($areas->pluck('id')->all(), $areas->sortBy('posicao')->pluck('id')->all());
        $this->assertSame($usuario->widgets()->count(), $areas->sum(fn ($area) => $area->widgets()->count()));
    }

    public function test_criar_area_vazia_ou_de_modelo_e_restaurar_so_funciona_com_modelo(): void
    {
        $usuario = User::factory()->gestor()->create();
        $this->padrao->garantirPara($usuario);

        $vazia = $this->padrao->criarArea($usuario, 'Vazia');
        $dePronta = $this->padrao->criarArea($usuario, 'Pronta', 'cobertura');
        $comModeloInexistente = $this->padrao->criarArea($usuario, 'Fantasma', 'nao_existe');

        $this->assertNull($vazia->modelo);
        $this->assertSame(0, $vazia->widgets()->count());
        $this->assertSame('cobertura', $dePronta->modelo);
        $this->assertGreaterThan(0, $dePronta->widgets()->count());
        $this->assertNull($comModeloInexistente->modelo, 'modelo desconhecido vira área vazia');

        $this->assertFalse($this->padrao->restaurarArea($vazia));

        $dePronta->widgets()->delete();
        $this->assertTrue($this->padrao->restaurarArea($dePronta));
        $this->assertGreaterThan(0, $dePronta->widgets()->count());
    }

    public function test_a_primeira_area_criada_para_quem_nao_tem_nenhuma_e_a_inicial(): void
    {
        $usuario = User::factory()->gestor()->create();

        $this->assertTrue($this->padrao->criarArea($usuario, 'Primeira')->padrao);
        $this->assertFalse($this->padrao->criarArea($usuario, 'Segunda')->padrao);
    }

    public function test_tipos_de_visual_larguras_dicas_e_aplicabilidade(): void
    {
        $this->assertSame([1, 2, 1, 2, 2], array_map(fn (TipoDeVisual $t): int => $t->larguraInicial(), [TipoDeVisual::Destaque, TipoDeVisual::Evolucao, TipoDeVisual::Ranking, TipoDeVisual::Mapa, TipoDeVisual::Matriz]));

        foreach (TipoDeVisual::cases() as $tipo) {
            $this->assertNotEmpty($tipo->rotulo());
            $this->assertNotEmpty($tipo->descricao());
            $this->assertSame($tipo === TipoDeVisual::Destaque, $tipo->dica() === '', $tipo->value);
        }

        $this->assertTrue(TipoDeVisual::Matriz->aplicaA('indice_prioridade'));
        $this->assertFalse(TipoDeVisual::Matriz->aplicaA('cobertura_esf'));
        $this->assertTrue(TipoDeVisual::Mapa->aplicaA('cobertura_esf'));
        $this->assertCount(5, TipoDeVisual::paraIndicador('indice_prioridade'));
        $this->assertCount(4, TipoDeVisual::paraIndicador('cobertura_esf'));
        $this->assertNotContains(TipoDeVisual::Matriz, TipoDeVisual::paraIndicador(Indicador::where('codigo', 'cobertura_esf')->first()));
    }
}
