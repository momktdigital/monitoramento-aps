<?php

namespace Tests\Unit;

use App\Integrations\Datasus\AgregadorDeInternacoes;
use App\Integrations\Datasus\AgregadorDeNascimentos;
use App\Integrations\Datasus\AgregadorDeObitos;
use App\Integrations\Datasus\ListaIcsap;
use App\Integrations\Datasus\NomesDeArquivo;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AgregadoresDoDatasusTest extends TestCase
{
    /** @var array<string, int> */
    private array $municipios = ['330610' => 3306107, '330420' => 3304201];

    private function internacao(string $diagnostico, string $residencia = '330610', string $ident = '1', string $ano = '2026', string $mes = '07'): array
    {
        return ['ANO_CMPT' => $ano, 'MES_CMPT' => $mes, 'IDENT' => $ident, 'MUNIC_RES' => $residencia, 'DIAG_PRINC' => $diagnostico];
    }

    public function test_internacoes_contam_por_municipio_e_competencia_separando_sensiveis(): void
    {
        $agregador = new AgregadorDeInternacoes(new ListaIcsap(require __DIR__.'/../../config/icsap.php'));

        $resultado = $agregador->agregar([
            $this->internacao('I64'),                       // sensível (grupo 12)
            $this->internacao('J153'),                      // sensível (grupo 6)
            $this->internacao('S628'),                      // não sensível
            $this->internacao('J150'),                      // pneumonia fora da lista
            $this->internacao('E109', '330420'),            // sensível, outro município
            $this->internacao('I64', '330610', '1', '2026', '06'), // mês anterior
        ], $this->municipios);

        $this->assertSame(['icsap' => 2, 'total' => 4], $resultado[3306107][202607]);
        $this->assertSame(['icsap' => 1, 'total' => 1], $resultado[3306107][202606]);
        $this->assertSame(['icsap' => 1, 'total' => 1], $resultado[3304201][202607]);
    }

    public function test_internacoes_ignoram_aih_de_continuacao_e_moradores_de_municipios_fora_do_universo(): void
    {
        $agregador = new AgregadorDeInternacoes(new ListaIcsap(require __DIR__.'/../../config/icsap.php'));

        $resultado = $agregador->agregar([
            $this->internacao('I64', '330610', '5'),        // AIH tipo 5 (continuação)
            $this->internacao('I64', '350010'),             // mora em outro estado/município não carregado
            $this->internacao('I64', ''),                   // sem município
            $this->internacao('I64', '330610', '1', 'abcd', '07'), // competência inválida
            $this->internacao('I64', '3306107'),            // código de 7 dígitos também casa pelos 6 primeiros
        ], $this->municipios);

        $this->assertSame([3306107 => [202607 => ['icsap' => 1, 'total' => 1]]], $resultado);
    }

    public function test_internacoes_obstetricas_ficam_fora_do_total_exceto_a_infeccao_urinaria_na_gestacao(): void
    {
        $agregador = new AgregadorDeInternacoes(new ListaIcsap(require __DIR__.'/../../config/icsap.php'));

        $resultado = $agregador->agregar([
            $this->internacao('O800'),    // parto: fora do total e fora do numerador
            $this->internacao('O42'),     // ruptura prematura: obstétrica fora da lista
            $this->internacao('O234'),    // infecção urinária na gestação: ICSAP, então entra nos dois
            $this->internacao('I64'),
        ], $this->municipios);

        $this->assertSame(['icsap' => 2, 'total' => 2], $resultado[3306107][202607], 'o numerador sempre está contido no denominador');
    }

    public function test_obitos_contam_so_menores_de_um_ano_nao_fetais(): void
    {
        $obitos = [
            ['TIPOBITO' => '2', 'CODMUNRES' => '330610', 'IDADE' => '012'],   // 12 minutos
            ['TIPOBITO' => '2', 'CODMUNRES' => '330610', 'IDADE' => '105'],   // 5 horas
            ['TIPOBITO' => '2', 'CODMUNRES' => '330610', 'IDADE' => '220'],   // 20 dias
            ['TIPOBITO' => '2', 'CODMUNRES' => '330610', 'IDADE' => '311'],   // 11 meses
            ['TIPOBITO' => '2', 'CODMUNRES' => '330610', 'IDADE' => '401'],   // 1 ano: não conta
            ['TIPOBITO' => '2', 'CODMUNRES' => '330610', 'IDADE' => '472'],   // 72 anos
            ['TIPOBITO' => '2', 'CODMUNRES' => '330610', 'IDADE' => '501'],   // 101 anos
            ['TIPOBITO' => '2', 'CODMUNRES' => '330610', 'IDADE' => '000'],   // ignorada
            ['TIPOBITO' => '2', 'CODMUNRES' => '330610', 'IDADE' => ''],      // vazia
            ['TIPOBITO' => '1', 'CODMUNRES' => '330610', 'IDADE' => '200'],   // fetal
            ['TIPOBITO' => '2', 'CODMUNRES' => '330420', 'IDADE' => '215'],
            ['TIPOBITO' => '2', 'CODMUNRES' => '350010', 'IDADE' => '215'],   // município fora do universo
        ];

        $this->assertSame([3306107 => 4, 3304201 => 1], (new AgregadorDeObitos)->agregar($obitos, $this->municipios));
    }

    public function test_nascimentos_calculam_pre_natal_e_baixo_peso_so_sobre_dados_informados(): void
    {
        $nascimentos = [
            ['CODMUNRES' => '330610', 'CONSULTAS' => '4', 'PESO' => '3200'],   // 7+ consultas, peso normal
            ['CODMUNRES' => '330610', 'CONSULTAS' => '4', 'PESO' => '2400'],   // 7+ consultas, baixo peso
            ['CODMUNRES' => '330610', 'CONSULTAS' => '3', 'PESO' => '2500'],   // 4 a 6, 2.500 g não é baixo peso
            ['CODMUNRES' => '330610', 'CONSULTAS' => '1', 'PESO' => '1500'],   // nenhuma consulta, baixo peso
            ['CODMUNRES' => '330610', 'CONSULTAS' => '9', 'PESO' => '9999'],   // ambos ignorados
            ['CODMUNRES' => '330610', 'CONSULTAS' => '', 'PESO' => ''],         // ambos vazios
            ['CODMUNRES' => '330610', 'CONSULTAS' => '2', 'PESO' => '0'],       // peso zero = não informado
            ['CODMUNRES' => '350010', 'CONSULTAS' => '4', 'PESO' => '3000'],   // fora do universo
        ];

        $this->assertSame(
            [3306107 => ['nascidos' => 7, 'consultas_informadas' => 5, 'consultas_7_ou_mais' => 2, 'peso_informado' => 4, 'baixo_peso' => 2]],
            (new AgregadorDeNascimentos)->agregar($nascimentos, $this->municipios),
        );
    }

    public function test_nomes_dos_arquivos_seguem_o_padrao_do_ftp_do_datasus(): void
    {
        $this->assertSame('/dissemin/publicos/SIHSUS/200801_/Dados/RDRJ2607.dbc', NomesDeArquivo::sih('RJ', 2026, 7));
        $this->assertSame('/dissemin/publicos/SIHSUS/200801_/Dados/RDRJ1001.dbc', NomesDeArquivo::sih('RJ', 2010, 1));
        $this->assertSame('/dissemin/publicos/SINASC/1996_/Dados/DNRES/DNRJ2024.dbc', NomesDeArquivo::sinasc('RJ', 2024));
        $this->assertSame('/dissemin/publicos/SIM/CID10/DORES/DORJ2024.dbc', NomesDeArquivo::sim('RJ', 2024));
    }

    public function test_nomes_de_arquivo_recusam_valores_fora_do_padrao(): void
    {
        foreach ([['sih', ['../etc', 2026, 7]], ['sih', ['rj', 2026, 7]], ['sih', ['RJ', 2026, 13]], ['sih', ['RJ', 1800, 1]], ['sinasc', ['RJ; rm', 2024]], ['sim', ['R', 2024]]] as [$metodo, $argumentos]) {
            try {
                NomesDeArquivo::$metodo(...$argumentos);
                $this->fail("{$metodo}(".json_encode($argumentos).') deveria ser recusado');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
