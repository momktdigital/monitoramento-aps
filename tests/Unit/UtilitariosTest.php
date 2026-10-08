<?php

namespace Tests\Unit;

use App\Domain\Indicators\CalculadorDeBenchmarks;
use App\Support\Competencia;
use App\Support\Duracao;
use App\Support\Numero;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UtilitariosTest extends TestCase
{
    /**
     * @return array<string, array{mixed, float|null}>
     */
    public static function numeros(): array
    {
        return [
            'inteiro' => [71449, 71449.0],
            'decimal' => [98.5, 98.5],
            'milhar com virgula (formato do e-Gestor)' => ['71,449', 71449.0],
            'milhar com virgula e decimal' => ['1,234.5', 1234.5],
            'milhar com ponto (formato brasileiro)' => ['71.449', 71449.0],
            'milhar com ponto e virgula decimal' => ['1.234,5', 1234.5],
            'decimal com virgula' => ['98,5', 98.5],
            'decimal com ponto' => ['98.5', 98.5],
            'percentual inteiro em texto' => ['100', 100.0],
            'negativo' => ['-3,5', -3.5],
            'vazio' => ['', null],
            'traco do IBGE' => ['-', null],
            'reticencias do IBGE' => ['...', null],
            'x de sigilo' => ['X', null],
            'nulo' => [null, null],
            'infinito' => [INF, null],
            'texto qualquer' => ['abc', null],
        ];
    }

    #[DataProvider('numeros')]
    public function test_analisa_numeros_de_fontes_publicas(mixed $entrada, ?float $esperado): void
    {
        $this->assertSame($esperado, Numero::analisar($entrada));
    }

    public function test_converte_competencias_nos_dois_formatos(): void
    {
        $this->assertSame(202607, Numero::competencia('07/2026'));
        $this->assertSame(202607, Numero::competencia('202607'));
        $this->assertNull(Numero::competencia('2026-07'));
        $this->assertNull(Numero::competencia(''));
    }

    public function test_rotulo_amigavel_da_competencia(): void
    {
        $this->assertSame('jul/2026', Competencia::rotulo(202607));
        $this->assertSame('dez/2022', Competencia::rotulo(202212));
        $this->assertSame('—', Competencia::rotulo(null));
        $this->assertSame('202613', Competencia::rotulo(202613));
    }

    public function test_formata_duracoes_em_texto_curto(): void
    {
        $this->assertSame('0 s', Duracao::formatar(-5));
        $this->assertSame('45 s', Duracao::formatar(45));
        $this->assertSame('12 min 05 s', Duracao::formatar(725));
        $this->assertSame('1 h 20 min', Duracao::formatar(4800));
    }

    public function test_percentil_por_interpolacao_linear(): void
    {
        $valores = [10.0, 20.0, 30.0, 40.0];

        $this->assertEqualsWithDelta(25.0, CalculadorDeBenchmarks::percentil($valores, 0.5), 0.0001);
        $this->assertEqualsWithDelta(17.5, CalculadorDeBenchmarks::percentil($valores, 0.25), 0.0001);
        $this->assertEqualsWithDelta(32.5, CalculadorDeBenchmarks::percentil($valores, 0.75), 0.0001);
        $this->assertSame(7.0, CalculadorDeBenchmarks::percentil([7.0], 0.9));
    }
}
