<?php

namespace Tests\Unit;

use App\Domain\Narrative\Analise;
use App\Domain\Narrative\Analista;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Support\Formatador;
use PHPUnit\Framework\TestCase;

class AnalistaTest extends TestCase
{
    private Analista $analista;

    private Municipio $valenca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analista = new Analista;
        $this->valenca = new Municipio(['id' => 3306107, 'nome' => 'Valença']);
    }

    private function indicador(string $polaridade = 'maior_melhor', string $unidade = '%', int $casas = 1, string $periodicidade = 'mensal', ?float $teto = null): Indicador
    {
        return new Indicador([
            'teto' => $teto,
            'codigo' => 'cobertura_esf', 'nome' => 'Cobertura da ESF', 'fonte' => 'egestor', 'unidade' => $unidade,
            'polaridade' => $polaridade, 'periodicidade' => $periodicidade, 'casas_decimais' => $casas,
        ]);
    }

    /**
     * @param  array<string, mixed>  $sobrescrever
     * @return array<string, mixed>
     */
    private function dados(array $sobrescrever = []): array
    {
        return $sobrescrever + [
            'competencia' => 202607,
            'valor' => 88.0,
            'anterior' => ['competencia' => 202507, 'valor' => 80.0],
            'mediana_regiao' => 75.0,
            'quantidade_regiao' => 12,
            'posicao' => ['posicao' => 14, 'total' => 92],
        ];
    }

    private function texto(Analise $analise): string
    {
        return implode(' ', $analise->paragrafos);
    }

    public function test_formata_numeros_e_valores_no_padrao_brasileiro(): void
    {
        $this->assertSame('71.442', Formatador::numero(71442));
        $this->assertSame('1.234,56', Formatador::numero(1234.56, 2));
        $this->assertSame('−3,5', Formatador::numero(-3.5, 1));
        $this->assertSame('0,0', Formatador::numero(-0.0001, 1));
        $this->assertSame('82,5%', Formatador::valor(82.5, $this->indicador()));
        $this->assertSame('R$ 1.234,50', Formatador::valor(1234.5, $this->indicador('neutra', 'R$', 2)));
        $this->assertSame('23 equipes', Formatador::valor(23, $this->indicador('maior_melhor', 'equipes', 0)));
        $this->assertSame('3,2 por 10 mil hab.', Formatador::valor(3.2, $this->indicador('maior_melhor', 'por 10 mil hab.')));
    }

    public function test_variacao_de_percentuais_usa_pontos_e_a_dos_demais_usa_percentual_relativo(): void
    {
        $this->assertSame('+2,3 p.p.', Formatador::variacao(80.0, 82.3, $this->indicador()));
        $this->assertSame('−1,0 p.p.', Formatador::variacao(80.0, 79.0, $this->indicador()));
        $this->assertSame('+10,0%', Formatador::variacao(20.0, 22.0, $this->indicador('maior_melhor', 'equipes', 0)));
        $this->assertSame('0,0 p.p.', Formatador::variacao(5.0, 5.0, $this->indicador()));
    }

    public function test_indicador_maior_melhor_acima_da_regiao_e_subindo_e_favoravel(): void
    {
        $analise = $this->analista->destaque($this->valenca, $this->indicador(), $this->dados());
        $texto = $this->texto($analise);

        $this->assertSame(Analise::FAVORAVEL, $analise->tom);
        $this->assertStringContainsString('Em Valença, o valor atual de “Cobertura da ESF” é 88,0% (jul/2026).', $texto);
        $this->assertStringContainsString('acima da mediana da região de saúde (75,0%), o que é uma situação melhor', $texto);
        $this->assertStringContainsString('Em relação a jul/2025 (80,0%), o valor subiu 8,0 p.p., uma melhora.', $texto);
        $this->assertStringContainsString('Entre os 92 municípios do estado com dado, Valença ocupa a 14ª posição (a 1ª é a melhor situação).', $texto);
    }

    public function test_indicador_menor_melhor_acima_da_regiao_e_subindo_pede_atencao(): void
    {
        $analise = $this->analista->destaque($this->valenca, $this->indicador('menor_melhor'), $this->dados());
        $texto = $this->texto($analise);

        $this->assertSame(Analise::ATENCAO, $analise->tom);
        $this->assertStringContainsString('acima da mediana da região de saúde (75,0%), o que merece atenção', $texto);
        $this->assertStringContainsString('subiu 8,0 p.p., uma piora.', $texto);
    }

    public function test_indicador_menor_melhor_abaixo_da_regiao_e_caindo_e_favoravel(): void
    {
        $analise = $this->analista->destaque($this->valenca, $this->indicador('menor_melhor'), $this->dados(['valor' => 60.0, 'anterior' => ['competencia' => 202507, 'valor' => 70.0]]));

        $this->assertSame(Analise::FAVORAVEL, $analise->tom);
        $this->assertStringContainsString('abaixo da mediana da região de saúde (75,0%), o que é uma situação melhor', $this->texto($analise));
        $this->assertStringContainsString('caiu 10,0 p.p., uma melhora.', $this->texto($analise));
    }

    public function test_indicador_neutro_descreve_sem_emitir_juizo_de_valor(): void
    {
        $analise = $this->analista->destaque($this->valenca, $this->indicador('neutra'), $this->dados());
        $texto = $this->texto($analise);

        $this->assertSame(Analise::INFORMATIVO, $analise->tom);
        $this->assertStringContainsString('acima da mediana da região de saúde (75,0%).', $texto);

        foreach (['melhor', 'atenção', 'melhora', 'piora', 'posição'] as $palavra) {
            $this->assertStringNotContainsString($palavra, $texto, "indicador neutro não pode usar “{$palavra}”");
        }
    }

    public function test_valores_dentro_de_3_por_cento_da_mediana_sao_praticamente_iguais(): void
    {
        $analise = $this->analista->destaque($this->valenca, $this->indicador(), $this->dados(['valor' => 76.0, 'anterior' => null]));

        $this->assertStringContainsString('praticamente igual à mediana da região de saúde (75,0%)', $this->texto($analise));
        $this->assertSame(Analise::INTERMEDIARIO, $analise->tom);
    }

    public function test_variacao_pequena_e_tratada_como_estabilidade(): void
    {
        $analise = $this->analista->destaque($this->valenca, $this->indicador(), $this->dados(['valor' => 80.05, 'anterior' => ['competencia' => 202507, 'valor' => 80.0], 'mediana_regiao' => null, 'posicao' => null]));

        $this->assertStringContainsString('o valor ficou estável.', $this->texto($analise));
        $this->assertSame(Analise::INTERMEDIARIO, $analise->tom);
    }

    public function test_regiao_com_poucos_municipios_nao_e_citada(): void
    {
        $analise = $this->analista->destaque($this->valenca, $this->indicador(), $this->dados(['quantidade_regiao' => 2]));

        $this->assertStringNotContainsString('mediana da região', $this->texto($analise));
    }

    public function test_sem_historico_nem_regiao_o_texto_so_informa_o_valor(): void
    {
        $analise = $this->analista->destaque($this->valenca, $this->indicador(), $this->dados(['anterior' => null, 'mediana_regiao' => null, 'quantidade_regiao' => null, 'posicao' => null]));

        $this->assertCount(1, $analise->paragrafos);
        $this->assertSame(Analise::INTERMEDIARIO, $analise->tom);
    }

    public function test_indicador_anual_mostra_so_o_ano_nos_textos(): void
    {
        $indicador = $this->indicador('neutra', 'pessoas', 0, 'anual');

        $analise = $this->analista->destaque($this->valenca, $indicador, $this->dados(['competencia' => 202512, 'valor' => 71449.0, 'anterior' => ['competencia' => 202412, 'valor' => 71462.0], 'mediana_regiao' => null, 'posicao' => null]));
        $texto = $this->texto($analise);

        $this->assertStringContainsString('é 71.449 pessoas (2025)', $texto);
        $this->assertStringContainsString('Em relação a 2024 (71.462 pessoas)', $texto);
        $this->assertStringNotContainsString('dez/', $texto);
    }

    public function test_evolucao_descreve_alta_extremos_e_comparacao_com_a_regiao(): void
    {
        $serie = [
            ['competencia' => 202604, 'valor' => 84.0], ['competencia' => 202605, 'valor' => 85.0],
            ['competencia' => 202606, 'valor' => 86.0], ['competencia' => 202607, 'valor' => 88.0],
        ];

        $analise = $this->analista->evolucao($this->valenca, $this->indicador(), $serie, [202607 => 75.0], 12);
        $texto = $this->texto($analise);

        $this->assertSame(Analise::FAVORAVEL, $analise->tom);
        $this->assertStringContainsString('Entre abr/2026 e jul/2026, em Valença, o valor de “Cobertura da ESF” subiu 4,0 p.p. (de 84,0% para 88,0%), uma melhora.', $texto);
        $this->assertStringContainsString('O maior valor do período foi 88,0% (jul/2026) e o menor, 84,0% (abr/2026).', $texto);
        $this->assertStringContainsString('No dado mais recente, o valor está acima da mediana da região de saúde (75,0%)', $texto);
    }

    public function test_evolucao_com_um_unico_ponto_explica_que_a_linha_se_forma_com_o_tempo(): void
    {
        $analise = $this->analista->evolucao($this->valenca, $this->indicador(), [['competencia' => 202607, 'valor' => 88.0]], [], null);

        $this->assertSame(Analise::INFORMATIVO, $analise->tom);
        $this->assertStringContainsString('apenas um dado', $analise->paragrafos[0]);
        $this->assertStringContainsString('se forma conforme novas atualizações', $analise->paragrafos[1]);
    }

    /**
     * @return list<array{municipio_id: int, nome: string, valor: float}>
     */
    private function ranking(int $quantidade): array
    {
        return array_map(fn (int $i): array => ['municipio_id' => 1000 + $i, 'nome' => "Cidade {$i}", 'valor' => 100.0 - $i], range(1, $quantidade));
    }

    public function test_ranking_classifica_pelo_terco_da_posicao(): void
    {
        $ranking = $this->ranking(12);
        $indicador = $this->indicador();

        $primeiro = $this->analista->ranking(new Municipio(['id' => 1001, 'nome' => 'Cidade 1']), $indicador, 202607, $ranking, 90.0);
        $meio = $this->analista->ranking(new Municipio(['id' => 1006, 'nome' => 'Cidade 6']), $indicador, 202607, $ranking, 90.0);
        $ultimo = $this->analista->ranking(new Municipio(['id' => 1012, 'nome' => 'Cidade 12']), $indicador, 202607, $ranking, 90.0);

        $this->assertSame(Analise::FAVORAVEL, $primeiro->tom);
        $this->assertSame(Analise::INTERMEDIARIO, $meio->tom);
        $this->assertSame(Analise::ATENCAO, $ultimo->tom);
        $this->assertStringContainsString('ocupa a 1ª posição', $primeiro->paragrafos[0]);
        $this->assertStringContainsString('A melhor situação é a de Cidade 1 (99,0%) e a pior, a de Cidade 12 (88,0%).', $primeiro->paragrafos[1]);
    }

    public function test_ranking_de_indicador_neutro_fala_em_maior_valor_e_e_informativo(): void
    {
        $analise = $this->analista->ranking(new Municipio(['id' => 1003, 'nome' => 'Cidade 3']), $this->indicador('neutra', 'pessoas', 0), 202512, $this->ranking(12), null);

        $this->assertSame(Analise::INFORMATIVO, $analise->tom);
        $this->assertStringContainsString('tem o 3º maior valor', $analise->paragrafos[0]);
        $this->assertStringContainsString('O maior valor é de Cidade 1', $analise->paragrafos[1]);
        $this->assertStringNotContainsString('melhor situação', $this->texto($analise));
    }

    public function test_ranking_sem_o_municipio_vira_mensagem_de_sem_dados(): void
    {
        $analise = $this->analista->ranking($this->valenca, $this->indicador(), 202607, $this->ranking(5), null);

        $this->assertSame(Analise::SEM_DADO, $analise->tom);
    }

    public function test_sem_dados_cita_a_fonte_e_orienta(): void
    {
        $analise = $this->analista->semDados($this->valenca, $this->indicador());

        $this->assertSame(Analise::SEM_DADO, $analise->tom);
        $this->assertSame('Sem dados', $analise->rotuloDoTom());
        $this->assertStringContainsString('Ainda não há dados de “Cobertura da ESF” para Valença.', $analise->paragrafos[0]);
        $this->assertStringContainsString('e-Gestor APS (Ministério da Saúde)', $analise->paragrafos[1]);
    }

    public function test_cobertura_acima_do_teto_nao_e_tratada_como_pior_que_uma_mediana_ainda_mais_alta(): void
    {
        $indicador = $this->indicador(teto: 100.0);

        $analise = $this->analista->destaque($this->valenca, $indicador, $this->dados(['valor' => 112.7, 'mediana_regiao' => 126.0, 'anterior' => null, 'posicao' => null]));
        $texto = $this->texto($analise);

        $this->assertSame(Analise::FAVORAVEL, $analise->tom);
        $this->assertStringContainsString('Esse valor já está no patamar pleno (a partir de 100,0%).', $texto);
        $this->assertStringContainsString('Esse valor e a mediana da região de saúde (126,0%) estão no patamar pleno (a partir de 100,0%): a diferença entre eles não indica situação melhor nem pior.', $texto);
        $this->assertStringNotContainsString('merece atenção', $texto);
        $this->assertStringNotContainsString('abaixo da mediana', $texto);
    }

    public function test_acima_do_teto_ainda_se_compara_com_uma_mediana_abaixo_do_teto(): void
    {
        $indicador = $this->indicador(teto: 100.0);

        $acima = $this->analista->destaque($this->valenca, $indicador, $this->dados(['valor' => 112.7, 'mediana_regiao' => 80.0, 'anterior' => null, 'posicao' => null]));
        $abaixo = $this->analista->destaque($this->valenca, $indicador, $this->dados(['valor' => 70.0, 'mediana_regiao' => 126.0, 'anterior' => null, 'posicao' => null]));

        $this->assertStringContainsString('acima da mediana da região de saúde (80,0%), o que é uma situação melhor', $this->texto($acima));
        $this->assertStringContainsString('abaixo da mediana da região de saúde (126,0%), o que merece atenção', $this->texto($abaixo));
        $this->assertSame(Analise::ATENCAO, $abaixo->tom);
    }

    public function test_tendencia_acima_do_teto_e_manutencao_do_patamar_pleno_e_nao_variacao(): void
    {
        $analise = $this->analista->destaque($this->valenca, $this->indicador(teto: 100.0), $this->dados(['valor' => 112.7, 'anterior' => ['competencia' => 202507, 'valor' => 105.0], 'mediana_regiao' => null, 'posicao' => null]));

        $this->assertStringContainsString('Em relação a jul/2025 (105,0%), o valor se manteve no patamar pleno (a partir de 100,0%).', $this->texto($analise));
        $this->assertStringNotContainsString('subiu', $this->texto($analise));
    }

    public function test_sair_do_patamar_pleno_conta_como_piora_e_chegar_nele_como_melhora(): void
    {
        $indicador = $this->indicador(teto: 100.0);

        $piora = $this->analista->destaque($this->valenca, $indicador, $this->dados(['valor' => 90.0, 'anterior' => ['competencia' => 202507, 'valor' => 112.0], 'mediana_regiao' => null, 'posicao' => null]));
        $melhora = $this->analista->destaque($this->valenca, $indicador, $this->dados(['valor' => 112.0, 'anterior' => ['competencia' => 202507, 'valor' => 90.0], 'mediana_regiao' => null, 'posicao' => null]));

        $this->assertStringContainsString('uma piora', $this->texto($piora));
        $this->assertStringContainsString('uma melhora', $this->texto($melhora));
    }

    public function test_evolucao_inteira_acima_do_teto_se_mantem_no_patamar_pleno(): void
    {
        $serie = [
            ['competencia' => 202604, 'valor' => 105.0], ['competencia' => 202605, 'valor' => 108.0],
            ['competencia' => 202606, 'valor' => 111.0], ['competencia' => 202607, 'valor' => 112.7],
        ];

        $analise = $this->analista->evolucao($this->valenca, $this->indicador(teto: 100.0), $serie, [202607 => 126.0], 12);

        $this->assertStringContainsString('se manteve no patamar pleno (de 105,0% para 112,7%) (a partir de 100,0%)', $this->texto($analise));
        $this->assertSame(Analise::FAVORAVEL, $analise->tom);
    }

    public function test_ranking_com_teto_agrupa_os_empatados_e_conta_quantos_estao_no_patamar_pleno(): void
    {
        $indicador = $this->indicador(teto: 100.0);
        $ranking = [
            ['municipio_id' => 1, 'nome' => 'A', 'valor' => 180.0], ['municipio_id' => 2, 'nome' => 'B', 'valor' => 126.0],
            ['municipio_id' => 3, 'nome' => 'C', 'valor' => 112.7], ['municipio_id' => 4, 'nome' => 'D', 'valor' => 90.0],
            ['municipio_id' => 5, 'nome' => 'E', 'valor' => 71.0],
        ];

        $terceiro = $this->analista->ranking(new Municipio(['id' => 3, 'nome' => 'C']), $indicador, 202607, $ranking, 126.0);
        $quarto = $this->analista->ranking(new Municipio(['id' => 4, 'nome' => 'D']), $indicador, 202607, $ranking, 126.0);

        $this->assertStringContainsString('ocupa a 1ª posição em “Cobertura da ESF” (a 1ª é a melhor situação), empatado com 2 municípios, com 112,7%.', $terceiro->paragrafos[0]);
        $this->assertStringContainsString('3 dos 5 municípios estão no patamar pleno (a partir de 100,0%). A pior situação é a de E (71,0%).', $terceiro->paragrafos[1]);
        $this->assertSame(Analise::FAVORAVEL, $terceiro->tom);
        $this->assertStringContainsString('ocupa a 4ª posição', $quarto->paragrafos[0]);
        $this->assertStringNotContainsString('empatado', $quarto->paragrafos[0]);
    }

    public function test_ranking_em_que_todos_estao_no_teto_informa_isso(): void
    {
        $ranking = [['municipio_id' => 1, 'nome' => 'A', 'valor' => 150.0], ['municipio_id' => 2, 'nome' => 'B', 'valor' => 101.0], ['municipio_id' => 3, 'nome' => 'C', 'valor' => 100.0]];

        $analise = $this->analista->ranking(new Municipio(['id' => 2, 'nome' => 'B']), $this->indicador(teto: 100.0), 202607, $ranking, 101.0);

        $this->assertStringContainsString('Todos os 3 municípios estão no patamar pleno (a partir de 100,0%).', $analise->paragrafos[1]);
    }

    public function test_indicadores_sem_teto_nao_mudam_de_comportamento(): void
    {
        $analise = $this->analista->destaque($this->valenca, $this->indicador(), $this->dados(['valor' => 112.7, 'mediana_regiao' => 126.0, 'anterior' => null, 'posicao' => null]));

        $this->assertStringContainsString('abaixo da mediana da região de saúde (126,0%), o que merece atenção', $this->texto($analise));
        $this->assertStringNotContainsString('patamar pleno', $this->texto($analise));
    }

    public function test_a_analise_sobrevive_ao_cache_ida_e_volta(): void
    {
        $original = $this->analista->destaque($this->valenca, $this->indicador(), $this->dados());

        $this->assertEquals($original, Analise::fromArray($original->toArray()));
    }
}
