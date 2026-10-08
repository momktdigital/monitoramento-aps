<?php

namespace Tests\Unit;

use App\Domain\Scoring\CompositorDeIndice;
use PHPUnit\Framework\TestCase;

class CompositorDeIndiceTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function pilares(): array
    {
        return [
            'estrutura' => ['peso' => 60, 'obrigatorio' => true, 'componentes' => ['cobertura' => ['peso' => 3], 'equipes' => ['peso' => 1]]],
            'processo' => ['peso' => 40, 'componentes' => ['pre_natal' => ['peso' => 1]]],
        ];
    }

    private function emUsoTodos(): array
    {
        return ['cobertura' => true, 'equipes' => true, 'pre_natal' => true];
    }

    public function test_media_ponderada_em_dois_niveis(): void
    {
        $resultado = (new CompositorDeIndice(0.7))->compor(
            $this->pilares(),
            ['cobertura' => 80.0, 'equipes' => 40.0, 'pre_natal' => 50.0],
            $this->emUsoTodos(),
        );

        // estrutura = (3*80 + 1*40) / 4 = 70; índice = (60*70 + 40*50) / 100 = 62
        $this->assertEqualsWithDelta(70.0, $resultado['pilares']['estrutura']['nota'], 0.0001);
        $this->assertEqualsWithDelta(62.0, $resultado['nota'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $resultado['confianca'], 0.0001);
        $this->assertTrue($resultado['calculado']);
        $this->assertSame([], $resultado['faltantes']);
    }

    public function test_dado_ausente_redistribui_o_peso_e_reduz_a_confianca_sem_virar_zero(): void
    {
        $resultado = (new CompositorDeIndice(0.7))->compor(
            $this->pilares(),
            ['cobertura' => 80.0, 'equipes' => null, 'pre_natal' => 50.0],
            $this->emUsoTodos(),
        );

        // estrutura só com cobertura = 80 (não 60, que seria tratar o ausente como zero)
        $this->assertEqualsWithDelta(80.0, $resultado['pilares']['estrutura']['nota'], 0.0001);
        $this->assertEqualsWithDelta(0.75, $resultado['pilares']['estrutura']['completude'], 0.0001);
        $this->assertEqualsWithDelta(68.0, $resultado['nota'], 0.0001);
        // confiança = (60*0,75 + 40*1) / 100
        $this->assertEqualsWithDelta(0.85, $resultado['confianca'], 0.0001);
        $this->assertSame(['equipes'], $resultado['faltantes']);
    }

    public function test_pilar_inteiro_ausente_redistribui_entre_os_demais_e_derruba_a_confianca(): void
    {
        $resultado = (new CompositorDeIndice(0.5))->compor(
            $this->pilares(),
            ['cobertura' => 80.0, 'equipes' => 80.0, 'pre_natal' => null],
            $this->emUsoTodos(),
        );

        $this->assertEqualsWithDelta(80.0, $resultado['nota'], 0.0001);
        $this->assertEqualsWithDelta(0.6, $resultado['confianca'], 0.0001);
        $this->assertTrue($resultado['calculado']);
    }

    public function test_abaixo_da_confianca_minima_o_indice_nao_e_calculado(): void
    {
        $resultado = (new CompositorDeIndice(0.7))->compor(
            $this->pilares(),
            ['cobertura' => 80.0, 'equipes' => 80.0, 'pre_natal' => null],
            $this->emUsoTodos(),
        );

        $this->assertNull($resultado['nota']);
        $this->assertFalse($resultado['calculado']);
        $this->assertEqualsWithDelta(0.6, $resultado['confianca'], 0.0001, 'a confiança continua informada mesmo sem o índice');
    }

    public function test_pilar_obrigatorio_sem_nenhum_dado_impede_o_calculo_mesmo_com_confianca_aceitavel(): void
    {
        $resultado = (new CompositorDeIndice(0.3))->compor(
            $this->pilares(),
            ['cobertura' => null, 'equipes' => null, 'pre_natal' => 90.0],
            $this->emUsoTodos(),
        );

        $this->assertFalse($resultado['calculado']);
        $this->assertNull($resultado['nota']);
    }

    public function test_indicador_fora_de_uso_e_ignorado_e_nao_conta_como_ausente(): void
    {
        $resultado = (new CompositorDeIndice(0.7))->compor(
            $this->pilares(),
            ['cobertura' => 80.0, 'equipes' => null, 'pre_natal' => 50.0],
            ['cobertura' => true, 'equipes' => false, 'pre_natal' => true],
        );

        $this->assertEqualsWithDelta(1.0, $resultado['confianca'], 0.0001);
        $this->assertSame([], $resultado['faltantes']);
        $this->assertEqualsWithDelta(80.0, $resultado['pilares']['estrutura']['nota'], 0.0001);
    }

    public function test_pilar_opcional_sem_nenhum_indicador_em_uso_sai_da_conta(): void
    {
        $resultado = (new CompositorDeIndice(0.7))->compor(
            $this->pilares(),
            ['cobertura' => 80.0, 'equipes' => 40.0, 'pre_natal' => null],
            ['cobertura' => true, 'equipes' => true, 'pre_natal' => false],
        );

        $this->assertArrayNotHasKey('processo', $resultado['pilares']);
        $this->assertEqualsWithDelta(70.0, $resultado['nota'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $resultado['confianca'], 0.0001);
    }

    public function test_pilar_obrigatorio_sem_indicador_em_uso_conta_como_ausente(): void
    {
        $resultado = (new CompositorDeIndice(0.5))->compor(
            $this->pilares(),
            ['cobertura' => null, 'equipes' => null, 'pre_natal' => 90.0],
            ['cobertura' => false, 'equipes' => false, 'pre_natal' => true],
        );

        $this->assertFalse($resultado['calculado'], 'sem fonte para o pilar obrigatório não há índice honesto');
        $this->assertEqualsWithDelta(0.4, $resultado['confianca'], 0.0001);
    }
}
