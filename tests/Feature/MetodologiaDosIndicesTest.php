<?php

namespace Tests\Feature;

use App\Enums\Dimensao;
use App\Enums\Polaridade;
use App\Enums\QuadranteIpf;
use App\Models\Indicador;
use App\Models\Metodologia;
use App\Widgets\CatalogoDeVisuais;
use Database\Seeders\IndicadorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetodologiaDosIndicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_primeira_sincronizacao_cria_a_versao_1_ativa_com_a_configuracao_do_arquivo(): void
    {
        $metodologia = Metodologia::sincronizar();

        $this->assertSame(1, $metodologia->versao);
        $this->assertTrue($metodologia->ativa);
        $this->assertSame(config('indices'), $metodologia->configuracao);
        $this->assertSame(Metodologia::hashDe(config('indices')), $metodologia->hash);
    }

    public function test_sincronizar_sem_mudancas_reaproveita_a_versao_ativa(): void
    {
        $primeira = Metodologia::sincronizar();
        $segunda = Metodologia::sincronizar();

        $this->assertTrue($primeira->is($segunda));
        $this->assertSame(1, Metodologia::count());
    }

    public function test_mudar_um_peso_cria_nova_versao_e_desativa_a_anterior(): void
    {
        $primeira = Metodologia::sincronizar();

        config(['indices.idaps.pilares.estrutura.peso' => 50]);
        $segunda = Metodologia::sincronizar();

        $this->assertSame(2, $segunda->versao);
        $this->assertTrue($segunda->fresh()->ativa);
        $this->assertFalse($primeira->fresh()->ativa);
        $this->assertSame(1, Metodologia::ativa()->count());
        $this->assertSame(50, $segunda->configuracao['idaps']['pilares']['estrutura']['peso']);
        $this->assertSame(35, $primeira->fresh()->configuracao['idaps']['pilares']['estrutura']['peso'], 'a versão antiga continua reproduzível');
    }

    public function test_voltar_a_uma_configuracao_antiga_reativa_a_versao_dela_em_vez_de_duplicar(): void
    {
        $original = config('indices');
        $primeira = Metodologia::sincronizar();

        config(['indices.idaps.pilares.estrutura.peso' => 50]);
        Metodologia::sincronizar();

        config(['indices' => $original]);
        $reativada = Metodologia::sincronizar();

        $this->assertTrue($reativada->is($primeira));
        $this->assertSame(2, Metodologia::count());
        $this->assertSame(1, Metodologia::ativa()->count());
    }

    public function test_o_hash_nao_depende_da_ordem_das_chaves_mas_depende_do_conteudo(): void
    {
        $a = ['x' => 1, 'y' => ['b' => 2, 'a' => 3]];
        $b = ['y' => ['a' => 3, 'b' => 2], 'x' => 1];

        $this->assertSame(Metodologia::hashDe($a), Metodologia::hashDe($b));
        $this->assertNotSame(Metodologia::hashDe($a), Metodologia::hashDe(['x' => 2, 'y' => ['b' => 2, 'a' => 3]]));
    }

    public function test_a_ordem_dos_itens_de_uma_lista_faz_diferenca(): void
    {
        $this->assertNotSame(Metodologia::hashDe(['l' => [1, 2]]), Metodologia::hashDe(['l' => [2, 1]]));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}> índice, pilar, indicador, configuração
     */
    private function componentesDaConfiguracao(): array
    {
        $lista = [];

        foreach (['ina', 'idaps'] as $indice) {
            foreach (config("indices.{$indice}.pilares") as $chave => $pilar) {
                foreach ($pilar['componentes'] as $codigo => $componente) {
                    $lista[] = [$indice, $chave, $codigo, $componente];
                }
            }
        }

        return $lista;
    }

    public function test_todo_indicador_da_metodologia_existe_no_catalogo_com_sentido_e_peso_validos(): void
    {
        $this->seed(IndicadorSeeder::class);

        foreach ($this->componentesDaConfiguracao() as [$indice, $pilar, $codigo, $componente]) {
            $this->assertTrue(Indicador::where('codigo', $codigo)->exists(), "{$codigo} ({$indice}/{$pilar}) não existe no catálogo");
            $this->assertContains($componente['sentido'], ['alto', 'baixo'], "{$codigo}: sentido inválido");
            $this->assertGreaterThan(0, $componente['peso'], "{$codigo}: peso deve ser positivo");
        }

        $this->assertTrue(Indicador::where('codigo', config('indices.ancora'))->exists());
    }

    public function test_no_idaps_o_sentido_acompanha_a_polaridade_do_catalogo(): void
    {
        $this->seed(IndicadorSeeder::class);

        foreach ($this->componentesDaConfiguracao() as [$indice, , $codigo, $componente]) {
            if ($indice !== 'idaps') {
                continue;
            }

            $polaridade = Indicador::where('codigo', $codigo)->firstOrFail()->polaridade;

            $this->assertNotSame(Polaridade::Neutra, $polaridade, "{$codigo}: indicador neutro não pode entrar no desempenho");
            $this->assertSame($polaridade === Polaridade::MaiorMelhor ? 'alto' : 'baixo', $componente['sentido'], "{$codigo}: sentido contradiz a polaridade");
        }
    }

    public function test_nenhum_indicador_conta_em_dois_indices_nem_na_dimensao_errada(): void
    {
        $this->seed(IndicadorSeeder::class);

        $vistos = [];

        foreach ($this->componentesDaConfiguracao() as [$indice, , $codigo]) {
            $this->assertArrayNotHasKey($codigo, $vistos, "{$codigo} está em mais de um lugar: contaria duas vezes");
            $vistos[$codigo] = $indice;

            $esperada = $indice === 'ina' ? Dimensao::Necessidade : Dimensao::Desempenho;
            $this->assertSame($esperada, Indicador::where('codigo', $codigo)->firstOrFail()->dimensao, "{$codigo} está na dimensão errada para o {$indice}");
        }
    }

    public function test_os_pilares_tem_peso_e_a_matriz_cobre_os_quatro_quadrantes(): void
    {
        foreach (['ina', 'idaps'] as $indice) {
            foreach (config("indices.{$indice}.pilares") as $chave => $pilar) {
                $this->assertGreaterThan(0, $pilar['peso'], "{$indice}/{$chave}");
                $this->assertNotEmpty($pilar['componentes'], "{$indice}/{$chave}");
            }
        }

        $quadrantes = config('indices.ipf.quadrantes');

        $this->assertCount(4, $quadrantes);

        $atribuidos = array_map(fn (string $chave): QuadranteIpf => QuadranteIpf::daChave($chave), $quadrantes);
        $this->assertCount(4, array_unique(array_map(fn (QuadranteIpf $q): int => $q->value, $atribuidos)), 'cada quadrante deve aparecer uma vez');

        foreach (['necessidade_alta_desempenho_baixo', 'necessidade_alta_desempenho_alto', 'necessidade_baixa_desempenho_baixo', 'necessidade_baixa_desempenho_alto'] as $chave) {
            $this->assertArrayHasKey($chave, $quadrantes);
        }

        $this->assertSame(QuadranteIpf::PrioridadeMaxima, $atribuidos['necessidade_alta_desempenho_baixo']);
        $this->assertSame(QuadranteIpf::EstruturaConsolidada, $atribuidos['necessidade_baixa_desempenho_alto']);
    }

    public function test_pilares_da_regra_de_efetividade_existem_no_idaps(): void
    {
        $pilares = config('indices.idaps.pilares');

        $this->assertArrayHasKey(config('indices.efetividade.pilar_estrutura'), $pilares);
        $this->assertArrayHasKey(config('indices.efetividade.pilar_resultado'), $pilares);
    }

    public function test_a_galeria_de_visuais_oferece_o_grupo_indices(): void
    {
        $this->seed(IndicadorSeeder::class);

        $grupos = app(CatalogoDeVisuais::class)->agrupados();

        $this->assertArrayHasKey('Índices', $grupos);
        $this->assertSame(['indice_ina', 'indice_idaps', 'indice_prioridade'], $grupos['Índices']->pluck('codigo')->all());
    }

    public function test_os_indices_calculados_estao_no_catalogo_na_dimensao_indices(): void
    {
        $this->seed(IndicadorSeeder::class);

        foreach (['indice_ina', 'indice_idaps', 'indice_prioridade'] as $codigo) {
            $indicador = Indicador::where('codigo', $codigo)->firstOrFail();

            $this->assertSame(Dimensao::Indices, $indicador->dimensao);
            $this->assertTrue($indicador->ativo);
            $this->assertTrue($indicador->visivel);
            $this->assertSame('Cálculo da plataforma (a partir dos indicadores)', $indicador->fonteRotulo());
        }

        $this->assertSame(Polaridade::MaiorMelhor, Indicador::where('codigo', 'indice_idaps')->firstOrFail()->polaridade);
    }
}
