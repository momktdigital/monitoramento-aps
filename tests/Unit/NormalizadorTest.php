<?php

namespace Tests\Unit;

use App\Domain\Scoring\Normalizador;
use PHPUnit\Framework\TestCase;

class NormalizadorTest extends TestCase
{
    public function test_menor_valor_recebe_zero_e_maior_recebe_cem(): void
    {
        $notas = Normalizador::notas([1 => 10.0, 2 => 30.0, 3 => 20.0, 4 => 40.0, 5 => 50.0]);

        $this->assertEqualsWithDelta(0.0, $notas[1], 0.0001);
        $this->assertEqualsWithDelta(25.0, $notas[3], 0.0001);
        $this->assertEqualsWithDelta(50.0, $notas[2], 0.0001);
        $this->assertEqualsWithDelta(75.0, $notas[4], 0.0001);
        $this->assertEqualsWithDelta(100.0, $notas[5], 0.0001);
    }

    public function test_empatados_dividem_a_mesma_nota_pela_media_das_posicoes(): void
    {
        // Três municípios no teto (100) ocupam as posições 2, 3 e 4 de 0 a 4: média 3 => nota 75.
        $notas = Normalizador::notas([1 => 50.0, 2 => 70.0, 3 => 100.0, 4 => 100.0, 5 => 100.0]);

        $this->assertEqualsWithDelta(75.0, $notas[3], 0.0001);
        $this->assertSame($notas[3], $notas[4]);
        $this->assertSame($notas[4], $notas[5]);
        $this->assertEqualsWithDelta(0.0, $notas[1], 0.0001);
        $this->assertEqualsWithDelta(25.0, $notas[2], 0.0001);
    }

    public function test_todos_iguais_ficam_no_meio_sem_dividir_por_zero(): void
    {
        $this->assertSame([1 => 50.0, 2 => 50.0, 3 => 50.0], Normalizador::notas([1 => 7.0, 2 => 7.0, 3 => 7.0]));
    }

    public function test_um_unico_valor_fica_no_meio_e_lista_vazia_devolve_vazio(): void
    {
        $this->assertSame([9 => 50.0], Normalizador::notas([9 => 123.0]));
        $this->assertSame([], Normalizador::notas([]));
    }

    public function test_a_nota_nao_depende_da_escala_nem_de_valores_extremos(): void
    {
        $normal = Normalizador::notas([1 => 1.0, 2 => 2.0, 3 => 3.0, 4 => 4.0]);
        $comOutlier = Normalizador::notas([1 => 1.0, 2 => 2.0, 3 => 3.0, 4 => 4000000.0]);

        $this->assertEquals($normal, $comOutlier, 'só a ordem importa: um valor absurdo não achata os demais');
    }

    public function test_ruido_de_ponto_flutuante_nao_quebra_empates(): void
    {
        $notas = Normalizador::notas([1 => 0.1 + 0.2, 2 => 0.3, 3 => 0.9]);

        $this->assertSame($notas[1], $notas[2]);
    }

    public function test_inverter_troca_o_sentido_da_nota(): void
    {
        $this->assertSame(100.0, Normalizador::inverter(0.0));
        $this->assertSame(25.0, Normalizador::inverter(75.0));
    }
}
