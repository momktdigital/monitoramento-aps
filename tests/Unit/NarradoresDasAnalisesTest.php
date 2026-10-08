<?php

namespace Tests\Unit;

use App\Domain\Narrative\Analise;
use App\Domain\Narrative\AnalistaDaMatriz;
use App\Domain\Narrative\AnalistaDeComparacao;
use App\Domain\Narrative\AnalistaDoMapa;
use App\Enums\Periodicidade;
use App\Enums\Polaridade;
use App\Enums\QuadranteIpf;
use App\Models\Indicador;
use App\Models\IndiceMunicipio;
use Tests\TestCase;

class NarradoresDasAnalisesTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $atributos
     */
    private function indices(array $atributos = []): IndiceMunicipio
    {
        return new IndiceMunicipio($atributos + [
            'municipio_id' => 1,
            'competencia' => 202607,
            'ina' => 80.0,
            'idaps' => 30.0,
            'idaps_estrutura' => 40.0,
            'idaps_resultado' => 30.0,
            'ipf_quadrante' => QuadranteIpf::PrioridadeMaxima,
            'ipf_pontuacao' => 75.0,
            'estrutura_sem_resultado' => false,
            'confianca_ina' => 1.0,
            'confianca_idaps' => 1.0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function indicador(array $atributos = []): Indicador
    {
        return (new Indicador)->forceFill($atributos + [
            'id' => 1,
            'codigo' => 'cobertura_esf',
            'nome' => 'Cobertura da ESF',
            'unidade' => '%',
            'casas_decimais' => 1,
            'polaridade' => Polaridade::MaiorMelhor,
            'periodicidade' => Periodicidade::Mensal,
        ]);
    }

    private function resumo(): array
    {
        return [1 => 20, 2 => 25, 3 => 22, 4 => 25];
    }

    // ------------------------------------------------------------------ Matriz

    public function test_matriz_sem_indices_e_sem_dado(): void
    {
        $analise = (new AnalistaDaMatriz)->analisar('Valença', null, null, $this->resumo(), null, 202607);

        $this->assertSame(Analise::SEM_DADO, $analise->tom);
        $this->assertStringContainsString('Não há índices calculados para Valença em jul/2026', $analise->paragrafos[0]);
    }

    public function test_matriz_descreve_os_indices_o_quadrante_a_posicao_e_o_estado(): void
    {
        $analise = (new AnalistaDaMatriz)->analisar('Valença', $this->indices(), null, $this->resumo(), ['posicao' => 12, 'total' => 92], 202607);

        $this->assertSame(Analise::ATENCAO, $analise->tom);
        $texto = implode(' ', $analise->paragrafos);
        $this->assertStringContainsString('a necessidade da população (INA) é 80 e o desempenho da Atenção Primária (IDAPS) é 30', $texto);
        $this->assertStringContainsString('Isso coloca Valença em “Prioridade máxima”', $texto);
        $this->assertStringContainsString('12ª posição entre 92 municípios', $texto);
        $this->assertStringContainsString('No estado, 20 de 92 municípios com os dois índices (22%) estão em “Prioridade máxima”', $texto);
    }

    public function test_matriz_tom_por_quadrante(): void
    {
        $analista = new AnalistaDaMatriz;

        foreach ([
            [QuadranteIpf::PrioridadeMaxima, Analise::ATENCAO],
            [QuadranteIpf::GrandePotencial, Analise::INTERMEDIARIO],
            [QuadranteIpf::OportunidadeModerada, Analise::INTERMEDIARIO],
            [QuadranteIpf::EstruturaConsolidada, Analise::FAVORAVEL],
        ] as [$quadrante, $tom]) {
            $this->assertSame($tom, $analista->analisar('X', $this->indices(['ipf_quadrante' => $quadrante]), null, $this->resumo(), null, 202607)->tom, $quadrante->rotulo());
        }
    }

    public function test_matriz_alerta_de_efetividade_vence_o_tom_favoravel(): void
    {
        $analise = (new AnalistaDaMatriz)->analisar('X', $this->indices(['ipf_quadrante' => QuadranteIpf::EstruturaConsolidada, 'estrutura_sem_resultado' => true, 'idaps_estrutura' => 75.0, 'idaps_resultado' => 25.0]), null, $this->resumo(), null, 202607);

        $this->assertSame(Analise::ATENCAO, $analise->tom);
        $this->assertStringContainsString('Ponto de atenção: a estrutura e a cobertura (nota 75) estão entre as mais fortes do estado, mas os resultados em saúde (nota 25) ficam abaixo', implode(' ', $analise->paragrafos));
    }

    public function test_matriz_tendencia_do_idaps(): void
    {
        $analista = new AnalistaDaMatriz;
        $atual = $this->indices(['idaps' => 30.0]);

        $subiu = implode(' ', $analista->analisar('X', $atual, $this->indices(['competencia' => 202606, 'idaps' => 25.0]), $this->resumo(), null, 202607)->paragrafos);
        $caiu = implode(' ', $analista->analisar('X', $atual, $this->indices(['competencia' => 202606, 'idaps' => 36.0]), $this->resumo(), null, 202607)->paragrafos);
        $estavel = implode(' ', $analista->analisar('X', $atual, $this->indices(['competencia' => 202606, 'idaps' => 30.4]), $this->resumo(), null, 202607)->paragrafos);
        $mesmoMes = implode(' ', $analista->analisar('X', $atual, $this->indices(['competencia' => 202607]), $this->resumo(), null, 202607)->paragrafos);

        $this->assertStringContainsString('Desde jun/2026, o IDAPS subiu 5 pontos', $subiu);
        $this->assertStringContainsString('Desde jun/2026, o IDAPS caiu 6 pontos', $caiu);
        $this->assertStringContainsString('Desde jun/2026, o IDAPS ficou estável', $estavel);
        $this->assertStringNotContainsString('Desde', $mesmoMes);
    }

    public function test_matriz_so_com_idaps_explica_por_que_nao_ha_quadrante(): void
    {
        $analise = (new AnalistaDaMatriz)->analisar('Valença', $this->indices(['ina' => null, 'ipf_quadrante' => null, 'ipf_pontuacao' => null]), null, $this->resumo(), null, 202607);

        $this->assertSame(Analise::INFORMATIVO, $analise->tom);
        $this->assertStringContainsString('o desempenho da Atenção Primária (IDAPS) é 30', $analise->paragrafos[0]);
        $this->assertStringContainsString('ainda não aparece na matriz porque a necessidade da população (INA) não pôde ser calculada', $analise->paragrafos[1]);
    }

    public function test_matriz_so_com_ina_explica_que_falta_o_idaps(): void
    {
        $analise = (new AnalistaDaMatriz)->analisar('Valença', $this->indices(['idaps' => null, 'ipf_quadrante' => null]), null, $this->resumo(), null, 202607);

        $this->assertStringContainsString('a necessidade da população (INA) é 80 (jul/2026), mas o desempenho da Atenção Primária (IDAPS) não pôde ser calculado', $analise->paragrafos[0]);
    }

    public function test_matriz_avisa_quando_a_confianca_e_parcial(): void
    {
        $analise = (new AnalistaDaMatriz)->analisar('X', $this->indices(['confianca_idaps' => 0.75]), null, $this->resumo(), null, 202607);

        $this->assertStringContainsString('o cálculo usou cerca de 75% da metodologia', implode(' ', $analise->paragrafos));

        $semAviso = (new AnalistaDaMatriz)->analisar('X', $this->indices(['confianca_idaps' => 0.95]), null, $this->resumo(), null, 202607);
        $this->assertStringNotContainsString('metodologia', implode(' ', $semAviso->paragrafos));
    }

    // ------------------------------------------------------------------ Comparação

    public function test_comparacao_com_menos_de_dois_municipios_pede_mais_um(): void
    {
        $analise = (new AnalistaDeComparacao)->analisar([1 => 'Valença'], [], [], [], 202607);

        $this->assertSame(Analise::SEM_DADO, $analise->tom);
        $this->assertStringContainsString('Escolha pelo menos dois municípios', $analise->paragrafos[0]);
    }

    public function test_comparacao_diz_quem_lidera_cada_indice_e_cita_os_quadrantes(): void
    {
        $indices = [
            1 => $this->indices(['municipio_id' => 1, 'ina' => 80.0, 'idaps' => 30.0, 'ipf_quadrante' => QuadranteIpf::PrioridadeMaxima]),
            2 => $this->indices(['municipio_id' => 2, 'ina' => 70.0, 'idaps' => 70.0, 'ipf_quadrante' => QuadranteIpf::GrandePotencial]),
            3 => $this->indices(['municipio_id' => 3, 'ina' => 20.0, 'idaps' => 90.0, 'ipf_quadrante' => QuadranteIpf::EstruturaConsolidada]),
        ];

        $analise = (new AnalistaDeComparacao)->analisar([1 => 'Valença', 2 => 'Resende', 3 => 'Niterói'], $indices, [], [], 202607);
        $texto = implode(' ', $analise->paragrafos);

        $this->assertSame(Analise::INFORMATIVO, $analise->tom);
        $this->assertStringContainsString('Quanto ao desempenho da Atenção Primária (IDAPS) em jul/2026, Niterói tem a maior nota (90) e Valença a menor (30): uma diferença de 60 pontos', $texto);
        $this->assertStringContainsString('Quanto à necessidade da população (INA) em jul/2026, Valença tem a maior nota (80) e Niterói a menor (20)', $texto);
        $this->assertStringContainsString('Na matriz INA × IDAPS: Valença em “Prioridade máxima”; Resende em “Grande potencial”; Niterói em “Estrutura consolidada”.', $texto);
    }

    public function test_comparacao_notas_proximas_contam_como_empate(): void
    {
        $indices = [1 => $this->indices(['idaps' => 60.0]), 2 => $this->indices(['idaps' => 62.0])];

        $texto = implode(' ', (new AnalistaDeComparacao)->analisar([1 => 'A', 2 => 'B'], $indices, [], [], 202607)->paragrafos);

        $this->assertStringContainsString('praticamente empatados (diferença de 2 pontos', $texto);
    }

    public function test_comparacao_cita_o_indicador_de_maior_diferenca_relativa_ignorando_neutros_e_diferencas_pequenas(): void
    {
        $cobertura = $this->indicador(['id' => 1, 'nome' => 'Cobertura da ESF']);
        $icsap = $this->indicador(['id' => 2, 'nome' => 'Internações evitáveis', 'unidade' => 'por 10 mil hab.', 'polaridade' => Polaridade::MenorMelhor]);
        $populacao = $this->indicador(['id' => 3, 'nome' => 'População', 'unidade' => 'pessoas', 'casas_decimais' => 0, 'polaridade' => Polaridade::Neutra]);
        $equipes = $this->indicador(['id' => 4, 'nome' => 'Equipes', 'unidade' => 'equipes', 'casas_decimais' => 0]);

        $valores = [
            1 => [1 => ['competencia' => 202607, 'valor' => 80.0], 2 => ['competencia' => 202607, 'valor' => 40.0], 3 => ['competencia' => 202512, 'valor' => 1000.0], 4 => ['competencia' => 202607, 'valor' => 20.0]],
            2 => [1 => ['competencia' => 202607, 'valor' => 76.0], 2 => ['competencia' => 202607, 'valor' => 20.0], 3 => ['competencia' => 202512, 'valor' => 900000.0], 4 => ['competencia' => 202607, 'valor' => 20.5]],
        ];

        $texto = implode(' ', (new AnalistaDeComparacao)->analisar([1 => 'A', 2 => 'B'], [], $valores, [$cobertura, $icsap, $populacao, $equipes], 202607)->paragrafos);

        $this->assertStringContainsString('a maior diferença está em “Internações evitáveis”: B tem 20,0 por 10 mil hab., enquanto A tem 40,0 por 10 mil hab.', $texto);
        $this->assertStringNotContainsString('População', $texto, 'indicador neutro não é comparado como melhor/pior');
    }

    public function test_comparacao_sem_nenhum_dado_comparavel_e_sem_dado(): void
    {
        $analise = (new AnalistaDeComparacao)->analisar([1 => 'A', 2 => 'B'], [], [], [], null);

        $this->assertSame(Analise::SEM_DADO, $analise->tom);
    }

    public function test_comparacao_acima_do_teto_nao_conta_como_diferenca(): void
    {
        $cobertura = $this->indicador(['teto' => 100.0]);
        $valores = [1 => [1 => ['competencia' => 202607, 'valor' => 112.0]], 2 => [1 => ['competencia' => 202607, 'valor' => 140.0]]];

        $analise = (new AnalistaDeComparacao)->analisar([1 => 'A', 2 => 'B'], [], $valores, [$cobertura], 202607);

        $this->assertSame(Analise::SEM_DADO, $analise->tom, '112% e 140% valem o mesmo: ninguém tem a maior diferença');
    }

    // ------------------------------------------------------------------ Mapa

    /**
     * @return array<int, float>
     */
    private function valores(int $quantidade): array
    {
        $valores = [];

        for ($i = 1; $i <= $quantidade; $i++) {
            $valores[$i] = (float) ($i * 10);
        }

        return $valores;
    }

    /**
     * @return array<int, string>
     */
    private function nomes(int $quantidade): array
    {
        return collect(range(1, $quantidade))->mapWithKeys(fn (int $i): array => [$i => "Município {$i}"])->all();
    }

    public function test_mapa_descreve_amplitude_e_mediana(): void
    {
        $analise = (new AnalistaDoMapa)->analisar($this->indicador(), 'jul/2026', $this->valores(10), $this->nomes(10), null, 10);

        $this->assertSame(Analise::INFORMATIVO, $analise->tom);
        $this->assertSame('Em jul/2026, “Cobertura da ESF” vai de 10,0% (Município 1) a 100,0% (Município 10) entre os 10 municípios com dado. A mediana do estado é 55,0%.', $analise->paragrafos[0]);
        $this->assertCount(1, $analise->paragrafos);
    }

    public function test_mapa_tom_pela_posicao_do_municipio_quando_ha_sentido_bom_ou_ruim(): void
    {
        $analista = new AnalistaDoMapa;
        $indicador = $this->indicador();

        $melhor = $analista->analisar($indicador, 'jul/2026', $this->valores(12), $this->nomes(12), 12, 12);
        $meio = $analista->analisar($indicador, 'jul/2026', $this->valores(12), $this->nomes(12), 6, 12);
        $pior = $analista->analisar($indicador, 'jul/2026', $this->valores(12), $this->nomes(12), 1, 12);

        $this->assertSame(Analise::FAVORAVEL, $melhor->tom);
        $this->assertStringContainsString('É a 1ª posição entre 12 municípios', $melhor->paragrafos[1]);
        $this->assertSame(Analise::INTERMEDIARIO, $meio->tom);
        $this->assertSame(Analise::ATENCAO, $pior->tom);
        $this->assertStringContainsString('É a 12ª posição', $pior->paragrafos[1]);
    }

    public function test_mapa_inverte_a_posicao_quando_menos_e_melhor(): void
    {
        $indicador = $this->indicador(['polaridade' => Polaridade::MenorMelhor]);

        $analise = (new AnalistaDoMapa)->analisar($indicador, 'jul/2026', $this->valores(12), $this->nomes(12), 1, 12);

        $this->assertSame(Analise::FAVORAVEL, $analise->tom);
        $this->assertStringContainsString('É a 1ª posição', $analise->paragrafos[1]);
    }

    public function test_mapa_indicador_neutro_nao_emite_juizo_nem_posicao(): void
    {
        $analise = (new AnalistaDoMapa)->analisar($this->indicador(['polaridade' => Polaridade::Neutra]), 'jul/2026', $this->valores(12), $this->nomes(12), 12, 12);

        $this->assertSame(Analise::INFORMATIVO, $analise->tom);
        $this->assertStringNotContainsString('posição', implode(' ', $analise->paragrafos));
        $this->assertStringContainsString('Município 12 tem 120,0%, acima da mediana do estado.', $analise->paragrafos[1]);
    }

    public function test_mapa_valor_proximo_da_mediana(): void
    {
        $valores = [1 => 50.0, 2 => 50.5, 3 => 51.0, 4 => 90.0, 5 => 10.0];

        $analise = (new AnalistaDoMapa)->analisar($this->indicador(['polaridade' => Polaridade::Neutra]), 'jul/2026', $valores, $this->nomes(5), 2, 5);

        $this->assertStringContainsString('Município 2 tem 50,5%, próximo da mediana do estado.', $analise->paragrafos[1]);
    }

    public function test_mapa_avisa_quantos_municipios_estao_sem_dado_com_singular_e_plural(): void
    {
        $um = (new AnalistaDoMapa)->analisar($this->indicador(), 'jul/2026', $this->valores(9), $this->nomes(10), null, 10);
        $tres = (new AnalistaDoMapa)->analisar($this->indicador(), 'jul/2026', $this->valores(7), $this->nomes(10), null, 10);

        $this->assertStringContainsString('1 município está sem dado nesta competência e aparece em cinza no mapa.', $um->paragrafos[1]);
        $this->assertStringContainsString('3 municípios estão sem dado nesta competência e aparecem em cinza no mapa.', $tres->paragrafos[1]);
    }

    public function test_mapa_municipio_em_foco_sem_dado_nao_gera_frase_sobre_ele(): void
    {
        $analise = (new AnalistaDoMapa)->analisar($this->indicador(), 'jul/2026', $this->valores(9), $this->nomes(10), 10, 10);

        $this->assertCount(2, $analise->paragrafos);
        $this->assertStringNotContainsString('Município 10 tem', implode(' ', $analise->paragrafos));
    }

    public function test_mapa_com_poucos_municipios_e_sem_dado(): void
    {
        $analise = (new AnalistaDoMapa)->analisar($this->indicador(), 'jul/2026', [1 => 50.0], $this->nomes(10), 1, 10);

        $this->assertSame(Analise::SEM_DADO, $analise->tom);
    }

    public function test_mapa_valores_acima_do_teto_empatam_na_posicao(): void
    {
        $indicador = $this->indicador(['teto' => 100.0]);
        $valores = [1 => 140.0, 2 => 112.0, 3 => 100.0, 4 => 60.0, 5 => 50.0, 6 => 40.0];

        $analise = (new AnalistaDoMapa)->analisar($indicador, 'jul/2026', $valores, $this->nomes(6), 2, 6);

        $this->assertStringContainsString('É a 1ª posição entre 6 municípios', $analise->paragrafos[1], '140%, 112% e 100% valem o mesmo: todos na 1ª posição');
    }
}
