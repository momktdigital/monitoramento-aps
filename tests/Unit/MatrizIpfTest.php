<?php

namespace Tests\Unit;

use App\Domain\Scoring\MatrizIpf;
use App\Enums\QuadranteIpf;
use PHPUnit\Framework\TestCase;

class MatrizIpfTest extends TestCase
{
    private MatrizIpf $matriz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matriz = new MatrizIpf([
            'necessidade_alta_desempenho_baixo' => 'prioridade_maxima',
            'necessidade_alta_desempenho_alto' => 'grande_potencial',
            'necessidade_baixa_desempenho_baixo' => 'oportunidade_moderada',
            'necessidade_baixa_desempenho_alto' => 'estrutura_consolidada',
        ]);
    }

    public function test_classifica_os_quatro_quadrantes(): void
    {
        $this->assertSame(QuadranteIpf::PrioridadeMaxima, $this->matriz->classificar(80, 20, 50, 50));
        $this->assertSame(QuadranteIpf::GrandePotencial, $this->matriz->classificar(80, 70, 50, 50));
        $this->assertSame(QuadranteIpf::OportunidadeModerada, $this->matriz->classificar(30, 20, 50, 50));
        $this->assertSame(QuadranteIpf::EstruturaConsolidada, $this->matriz->classificar(30, 70, 50, 50));
    }

    public function test_valor_igual_ao_corte_conta_como_alto(): void
    {
        $this->assertSame(QuadranteIpf::GrandePotencial, $this->matriz->classificar(50, 50, 50, 50));
    }

    public function test_cada_indice_usa_o_proprio_corte(): void
    {
        $this->assertSame(QuadranteIpf::PrioridadeMaxima, $this->matriz->classificar(40, 60, 35, 65));
        $this->assertSame(QuadranteIpf::EstruturaConsolidada, $this->matriz->classificar(40, 60, 45, 55));
    }

    public function test_corte_pela_mediana_ou_por_numero_fixo(): void
    {
        $this->assertEqualsWithDelta(30.0, MatrizIpf::corte([10.0, 50.0, 30.0, 90.0, 20.0], 'mediana'), 0.0001);
        $this->assertEqualsWithDelta(35.0, MatrizIpf::corte([10.0, 20.0, 50.0, 90.0], 'mediana'), 0.0001);
        $this->assertSame(40.0, MatrizIpf::corte([10.0, 90.0], 40));
        $this->assertSame(50.0, MatrizIpf::corte([], 'mediana'));
    }

    public function test_pontuacao_de_prioridade_cresce_com_necessidade_e_com_baixo_desempenho(): void
    {
        $this->assertSame(100.0, MatrizIpf::pontuacao(100, 0));
        $this->assertSame(0.0, MatrizIpf::pontuacao(0, 100));
        $this->assertSame(50.0, MatrizIpf::pontuacao(50, 50));
        $this->assertGreaterThan(MatrizIpf::pontuacao(60, 60), MatrizIpf::pontuacao(60, 40));
    }

    public function test_regra_de_efetividade_estrutura_forte_e_resultado_fraco(): void
    {
        $regra = ['estrutura_minima' => 60, 'resultado_maximo' => 40];

        $this->assertTrue(MatrizIpf::estruturaSemResultado(75.0, 30.0, $regra));
        $this->assertTrue(MatrizIpf::estruturaSemResultado(60.0, 40.0, $regra), 'os limites contam');
        $this->assertFalse(MatrizIpf::estruturaSemResultado(75.0, 55.0, $regra));
        $this->assertFalse(MatrizIpf::estruturaSemResultado(50.0, 30.0, $regra));
        $this->assertFalse(MatrizIpf::estruturaSemResultado(null, 30.0, $regra), 'sem dado não se acusa ninguém');
        $this->assertFalse(MatrizIpf::estruturaSemResultado(75.0, null, $regra));
    }

    public function test_cada_quadrante_tem_chave_rotulo_e_descricao(): void
    {
        foreach (QuadranteIpf::cases() as $quadrante) {
            $this->assertSame($quadrante, QuadranteIpf::daChave($quadrante->chave()));
            $this->assertNotEmpty($quadrante->rotulo());
            $this->assertGreaterThan(20, strlen($quadrante->descricao()));
        }
    }
}
