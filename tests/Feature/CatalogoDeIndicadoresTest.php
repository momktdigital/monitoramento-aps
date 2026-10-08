<?php

namespace Tests\Feature;

use App\Enums\Dimensao;
use App\Models\Indicador;
use Database\Seeders\IndicadorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogoDeIndicadoresTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_catalogo_cria_todos_os_indicadores_com_codigos_unicos(): void
    {
        $this->seed(IndicadorSeeder::class);

        $definicoes = require database_path('seeders/data/indicadores.php');

        $this->assertSame(count($definicoes), Indicador::count());
        $this->assertSame(count($definicoes), Indicador::distinct()->count('codigo'));
    }

    public function test_todo_indicador_tem_os_quatro_textos_de_ajuda_preenchidos(): void
    {
        $this->seed(IndicadorSeeder::class);

        foreach (Indicador::all() as $indicador) {
            foreach (['o_que_e', 'como_calcula', 'para_que_serve', 'como_interpretar'] as $campo) {
                $this->assertGreaterThan(20, mb_strlen($indicador->{$campo}), "{$indicador->codigo}: texto '{$campo}' ausente ou curto demais");
            }
        }
    }

    public function test_o_catalogo_cobre_as_tres_dimensoes_e_tem_indicadores_ativos(): void
    {
        $this->seed(IndicadorSeeder::class);

        foreach (Dimensao::cases() as $dimensao) {
            $this->assertGreaterThan(0, Indicador::daDimensao($dimensao)->count(), "Sem indicadores em {$dimensao->value}");
        }

        $this->assertGreaterThan(0, Indicador::ativo()->count());
    }

    public function test_reexecutar_o_seeder_nao_duplica_nem_sobrescreve_textos_editados(): void
    {
        $this->seed(IndicadorSeeder::class);
        $total = Indicador::count();

        Indicador::where('codigo', 'cobertura_esf')->update(['o_que_e' => 'Texto revisado pela equipe.', 'unidade' => 'x']);

        $this->seed(IndicadorSeeder::class);

        $indicador = Indicador::where('codigo', 'cobertura_esf')->firstOrFail();

        $this->assertSame($total, Indicador::count());
        $this->assertSame('Texto revisado pela equipe.', $indicador->o_que_e);
        $this->assertSame('%', $indicador->unidade);
    }

    public function test_a_ordem_segue_a_posicao_no_catalogo(): void
    {
        $this->seed(IndicadorSeeder::class);

        $ordens = Indicador::orderBy('ordem')->pluck('ordem')->all();

        $this->assertSame(range(1, count($ordens)), $ordens);
    }

    public function test_indicadores_de_apoio_do_datasus_ficam_ativos_mas_fora_do_catalogo_de_visuais(): void
    {
        $this->seed(IndicadorSeeder::class);

        $visiveis = Indicador::ativo()->visivel()->pluck('codigo')->all();

        foreach (['icsap_internacoes_mes', 'internacoes_clinicas_mes'] as $apoio) {
            $this->assertTrue(Indicador::where('codigo', $apoio)->value('ativo'), "{$apoio} precisa estar ativo para o conector gravar");
            $this->assertNotContains($apoio, $visiveis);
        }

        foreach (['icsap_taxa', 'icsap_percentual', 'mortalidade_infantil', 'pre_natal_7_consultas', 'baixo_peso_nascer'] as $exibido) {
            $this->assertContains($exibido, $visiveis);
        }
    }

    public function test_texto_do_catalogo_so_substitui_o_editado_quando_a_versao_do_texto_sobe(): void
    {
        $this->seed(IndicadorSeeder::class);

        Indicador::where('codigo', 'cobertura_esf')->update(['o_que_e' => 'Texto antigo.', 'texto_versao' => 0]);
        Indicador::where('codigo', 'cobertura_aps')->update(['o_que_e' => 'Texto editado depois.', 'texto_versao' => 99]);

        $this->seed(IndicadorSeeder::class);

        $this->assertNotSame('Texto antigo.', Indicador::where('codigo', 'cobertura_esf')->value('o_que_e'));
        $this->assertSame('Texto editado depois.', Indicador::where('codigo', 'cobertura_aps')->value('o_que_e'));
    }
}
