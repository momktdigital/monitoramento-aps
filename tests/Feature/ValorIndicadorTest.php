<?php

namespace Tests\Feature;

use App\Enums\EscopoBenchmark;
use App\Models\Benchmark;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Models\User;
use App\Models\ValorIndicador;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ValorIndicadorTest extends TestCase
{
    use RefreshDatabase;

    public function test_gravar_em_lote_e_idempotente_e_atualiza_valores_existentes(): void
    {
        $municipio = Municipio::factory()->create();
        $indicador = Indicador::factory()->create();

        $linha = ['municipio_id' => $municipio->id, 'indicador_id' => $indicador->id, 'competencia' => 202601, 'valor' => 80.5];

        ValorIndicador::gravarEmLote([$linha]);
        ValorIndicador::gravarEmLote([$linha, ['competencia' => 202602, 'valor' => 70.0] + $linha]);
        ValorIndicador::gravarEmLote([['valor' => 85.25, 'numerador' => 17, 'denominador' => 20] + $linha]);

        $this->assertSame(2, ValorIndicador::count());

        $atualizado = ValorIndicador::where('competencia', 202601)->firstOrFail();

        $this->assertSame(85.25, $atualizado->valor);
        $this->assertSame(17.0, $atualizado->numerador);
        $this->assertSame(20.0, $atualizado->denominador);
    }

    public function test_lote_vazio_nao_faz_nada(): void
    {
        $this->assertSame(0, ValorIndicador::gravarEmLote([]));
    }

    public function test_a_chave_composta_impede_duplicidade_fora_do_upsert(): void
    {
        $valor = ValorIndicador::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('valores_indicador')->insert($valor->only(['municipio_id', 'indicador_id', 'competencia']) + ['valor' => 1]);
    }

    public function test_excluir_municipio_ou_indicador_remove_seus_valores(): void
    {
        $valor = ValorIndicador::factory()->create();

        Municipio::whereKey($valor->municipio_id)->delete();

        $this->assertSame(0, ValorIndicador::count());
    }

    public function test_os_indices_de_consulta_existem_na_tabela_de_valores(): void
    {
        $indices = collect(Schema::getIndexes('valores_indicador'));

        $this->assertSame(['municipio_id', 'indicador_id', 'competencia'], $indices->firstWhere('primary', true)['columns']);
        $this->assertSame(['indicador_id', 'competencia', 'municipio_id'], $indices->firstWhere('name', 'valores_ranking_idx')['columns']);
    }

    public function test_benchmark_guarda_estatisticas_por_escopo(): void
    {
        $benchmark = Benchmark::factory()->create(['escopo' => EscopoBenchmark::RegiaoSaude, 'escopo_id' => 33004]);

        $lido = Benchmark::where('indicador_id', $benchmark->indicador_id)->firstOrFail();

        $this->assertSame(EscopoBenchmark::RegiaoSaude, $lido->escopo);
        $this->assertSame(33004, $lido->escopo_id);
        $this->assertSame(62.0, $lido->mediana);
    }

    public function test_usuario_pode_ter_um_municipio_padrao_e_o_vinculo_e_desfeito_ao_excluir_o_municipio(): void
    {
        $municipio = Municipio::factory()->create();
        $usuario = User::factory()->create(['municipio_id' => $municipio->id]);

        $this->assertTrue($usuario->municipio->is($municipio));

        $municipio->delete();

        $this->assertNull($usuario->fresh()->municipio_id);
    }

    public function test_busca_por_nome_ignora_acentos_e_caixa(): void
    {
        Municipio::factory()->create(['nome' => 'Valença', 'nome_busca' => 'valenca']);
        Municipio::factory()->create(['nome' => 'Resende', 'nome_busca' => 'resende']);

        $this->assertSame(['Valença'], Municipio::buscarPorNome('VALENÇA')->pluck('nome')->all());
        $this->assertSame(['Valença'], Municipio::buscarPorNome('valen')->pluck('nome')->all());
    }
}
