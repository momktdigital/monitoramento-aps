<?php

namespace Tests\Unit;

use App\Enums\Periodicidade;
use App\Enums\Polaridade;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Widgets\ConstrutorDoMapa;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ConstrutorDoMapaTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $atributos
     */
    private function indicador(array $atributos = []): Indicador
    {
        return (new Indicador)->forceFill($atributos + ['id' => 1, 'codigo' => 'x', 'nome' => 'Indicador X', 'unidade' => '%', 'casas_decimais' => 1, 'polaridade' => Polaridade::MaiorMelhor, 'periodicidade' => Periodicidade::Mensal]);
    }

    /**
     * @return Collection<int, Municipio>
     */
    private function municipios(int $quantidade): Collection
    {
        return collect(range(1, $quantidade))->mapWithKeys(fn (int $i): array => [
            3300000 + $i => new Municipio(['id' => 3300000 + $i, 'nome' => "Município {$i}", 'regiao_saude_nome' => 'MEDIO PARAIBA']),
        ]);
    }

    /**
     * @return array<int, float>
     */
    private function valores(int ...$valores): array
    {
        $mapa = [];

        foreach ($valores as $posicao => $valor) {
            $mapa[3300001 + $posicao] = (float) $valor;
        }

        return $mapa;
    }

    public function test_dez_valores_distintos_formam_cinco_faixas_com_a_rampa_completa(): void
    {
        $mapa = (new ConstrutorDoMapa)->construir($this->indicador(), 'jul/2026', $this->valores(10, 20, 30, 40, 50, 60, 70, 80, 90, 100), $this->municipios(10), null, 33);

        $this->assertSame(['#cde2fb', '#9ec5f4', '#6da7ec', '#2a78d6', '#184f95'], array_column($mapa['faixas'], 'cor'));
        $this->assertSame('10,0% a 28,0%', $mapa['faixas'][0]['rotulo']);
        $this->assertSame('82,0% a 100,0%', $mapa['faixas'][4]['rotulo']);
    }

    public function test_municipios_nas_pontas_recebem_a_classe_mais_clara_e_a_mais_escura(): void
    {
        $mapa = (new ConstrutorDoMapa)->construir($this->indicador(), 'jul/2026', $this->valores(10, 20, 30, 40, 50, 60, 70, 80, 90, 100), $this->municipios(10), null, 33);

        $cores = collect($mapa['regioes'])->keyBy('name')->map->cor;

        $this->assertSame('#cde2fb', $cores['3300001']);
        $this->assertSame('#184f95', $cores['3300010']);
    }

    public function test_valores_repetidos_reduzem_a_quantidade_de_faixas_e_usam_cores_espacadas(): void
    {
        $mapa = (new ConstrutorDoMapa)->construir($this->indicador(), 'jul/2026', $this->valores(10, 10, 10, 10, 10, 10, 50, 50, 50, 90), $this->municipios(10), null, 33);

        // Quintis: 10, 10 (repetido, descartado), 26, 50 e 90.
        $this->assertCount(4, $mapa['faixas']);
        $this->assertSame(['#cde2fb', '#9ec5f4', '#2a78d6', '#184f95'], array_column($mapa['faixas'], 'cor'));
        $this->assertSame('10,0%', $mapa['faixas'][0]['rotulo'], 'faixa de um valor só leva o próprio valor');
        $this->assertSame('10,0% a 26,0%', $mapa['faixas'][1]['rotulo']);
    }

    public function test_todos_iguais_formam_uma_faixa_so_com_o_rotulo_do_valor(): void
    {
        $mapa = (new ConstrutorDoMapa)->construir($this->indicador(), 'jul/2026', $this->valores(40, 40, 40), $this->municipios(3), null, 33);

        $this->assertCount(1, $mapa['faixas']);
        $this->assertSame('40,0%', $mapa['faixas'][0]['rotulo']);
        $this->assertSame('#6da7ec', $mapa['faixas'][0]['cor']);
    }

    public function test_municipio_sem_valor_fica_cinza_e_e_contado(): void
    {
        $mapa = (new ConstrutorDoMapa)->construir($this->indicador(), 'jul/2026', $this->valores(10, 20, 30), $this->municipios(5), null, 33);

        $this->assertSame(2, $mapa['sem_dado']);

        $semDado = collect($mapa['regioes'])->firstWhere('name', '3300005');

        $this->assertSame('#e8e6df', $semDado['cor']);
        $this->assertNull($semDado['valor']);
        $this->assertSame('Sem dado nesta competência', $semDado['texto']);
    }

    public function test_o_municipio_em_foco_vem_por_ultimo_e_e_identificado(): void
    {
        $mapa = (new ConstrutorDoMapa)->construir($this->indicador(), 'jul/2026', $this->valores(10, 20, 30, 40), $this->municipios(4), 3300002, 33);

        $this->assertSame('3300002', $mapa['selecionado']);
        $this->assertSame(3300002, $mapa['regioes'][3]['id']);
    }

    public function test_sem_nenhum_valor_nao_ha_faixas_e_todos_ficam_cinza(): void
    {
        $mapa = (new ConstrutorDoMapa)->construir($this->indicador(), 'jul/2026', [], $this->municipios(3), null, 33);

        $this->assertSame([], $mapa['faixas']);
        $this->assertSame(3, $mapa['sem_dado']);
        $this->assertSame(['#e8e6df'], collect($mapa['regioes'])->pluck('cor')->unique()->all());
    }

    public function test_cada_regiao_traz_o_texto_formatado_com_a_unidade(): void
    {
        $indicador = $this->indicador(['unidade' => 'por 10 mil hab.']);

        $mapa = (new ConstrutorDoMapa)->construir($indicador, 'jul/2026', $this->valores(10, 20), $this->municipios(2), null, 33);

        $this->assertSame('10,0 por 10 mil hab.', collect($mapa['regioes'])->firstWhere('name', '3300001')['texto']);
        $this->assertSame('10,0 a 12,0', $mapa['faixas'][0]['rotulo'], 'só o percentual leva o símbolo nas faixas');
    }

    public function test_tabela_vai_da_melhor_para_a_pior_situacao(): void
    {
        $maiorMelhor = (new ConstrutorDoMapa)->tabela($this->indicador(), $this->valores(10, 30, 20), $this->municipios(3));
        $menorMelhor = (new ConstrutorDoMapa)->tabela($this->indicador(['polaridade' => Polaridade::MenorMelhor]), $this->valores(10, 30, 20), $this->municipios(3));
        $neutro = (new ConstrutorDoMapa)->tabela($this->indicador(['polaridade' => Polaridade::Neutra]), $this->valores(10, 30, 20), $this->municipios(3));

        $this->assertSame([3300002, 3300003, 3300001], $maiorMelhor['ids']);
        $this->assertSame([3300001, 3300003, 3300002], $menorMelhor['ids']);
        $this->assertSame([3300002, 3300003, 3300001], $neutro['ids'], 'sem sentido bom ou ruim: do maior para o menor');
        $this->assertSame(['Município 2', 'Medio Paraiba', '30,0%'], $maiorMelhor['linhas'][0]);
    }

    public function test_tabela_ignora_valores_de_municipios_fora_da_lista(): void
    {
        $tabela = (new ConstrutorDoMapa)->tabela($this->indicador(), [3309999 => 10.0, 3300001 => 20.0], $this->municipios(1));

        $this->assertSame([3300001], $tabela['ids']);
    }
}
