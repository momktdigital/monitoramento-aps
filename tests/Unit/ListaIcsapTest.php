<?php

namespace Tests\Unit;

use App\Integrations\Datasus\ListaIcsap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListaIcsapTest extends TestCase
{
    private ListaIcsap $lista;

    /** @var array<int, array{nome: string, cids: list<string>}> */
    private array $grupos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grupos = require __DIR__.'/../../config/icsap.php';
        $this->lista = new ListaIcsap($this->grupos);
    }

    public function test_a_lista_tem_os_19_grupos_da_portaria_e_codigos_bem_formados(): void
    {
        $this->assertSame(range(1, 19), array_keys($this->grupos));

        foreach ($this->grupos as $numero => $grupo) {
            $this->assertNotEmpty($grupo['nome']);
            $this->assertNotEmpty($grupo['cids'], "grupo {$numero} sem códigos");

            foreach ($grupo['cids'] as $cid) {
                $this->assertMatchesRegularExpression('/^[A-Z]\d{2}\d?$/', $cid, "código {$cid} do grupo {$numero} fora do padrão (sem ponto, 3 ou 4 caracteres)");
            }
        }
    }

    public function test_nenhum_codigo_aparece_em_dois_grupos_diferentes_com_o_mesmo_alcance(): void
    {
        $vistos = [];

        foreach ($this->grupos as $numero => $grupo) {
            foreach ($grupo['cids'] as $cid) {
                $this->assertArrayNotHasKey($cid, $vistos, "{$cid} repetido nos grupos {$numero} e ".($vistos[$cid] ?? '?'));
                $vistos[$cid] = $numero;
            }
        }
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function diagnosticos(): array
    {
        return [
            // Grupo 1: imunização e outras
            'coqueluche' => ['A37', 1],
            'coqueluche com 4º caractere' => ['A370', 1],
            'tétano A33 a A35' => ['A34', 1],
            'meningite por Haemophilus (subcategoria)' => ['G000', 1],
            'outras meningites bacterianas NÃO' => ['G001', null],
            'tuberculose pulmonar A15.x' => ['A150', 1],
            'tuberculose A16.x' => ['A169', 1],
            'meningite tuberculosa A17.0' => ['A170', 1],
            'tuberculose A17.1 a A17.9' => ['A178', 1],
            'tuberculose miliar' => ['A19', 1],
            'outras tuberculoses A18' => ['A185', 1],
            'febre reumática I00 a I02' => ['I01', 1],
            'sífilis A51 a A53' => ['A52', 1],
            'malária B50 a B54' => ['B54', 1],
            'ascaridíase' => ['B77', 1],
            // Grupos 2 a 5
            'desidratação' => ['E86', 2],
            'gastroenterite A00 a A09' => ['A09', 2],
            'anemia por deficiência de ferro' => ['D50', 3],
            'desnutrição E40 a E46' => ['E43', 4],
            'outras deficiências E50 a E64' => ['E64', 4],
            'otite média supurativa' => ['H66', 5],
            'infecção aguda de vias aéreas superiores' => ['J06', 5],
            'rinite crônica' => ['J31', 5],
            // Grupos 6 a 8
            'pneumonia pneumocócica' => ['J13', 6],
            'pneumonia por Streptococcus J15.3' => ['J153', 6],
            'pneumonia bacteriana NE J15.9' => ['J159', 6],
            'pneumonia lobar J18.1' => ['J181', 6],
            'outras pneumonias J15.0 NÃO' => ['J150', null],
            'pneumonia J15 sem subcategoria NÃO' => ['J15', null],
            'pneumonia J18.9 NÃO' => ['J189', null],
            'asma' => ['J45', 7],
            'bronquite aguda' => ['J20', 8],
            'doença pulmonar obstrutiva crônica' => ['J44', 8],
            'bronquiectasia' => ['J47', 8],
            // Grupos 9 a 14
            'hipertensão essencial' => ['I10', 9],
            'cardiopatia hipertensiva' => ['I119', 9],
            'angina' => ['I20', 10],
            'angina com 4º caractere' => ['I209', 10],
            'insuficiência cardíaca' => ['I50', 11],
            'edema agudo de pulmão' => ['J81', 11],
            'AVC não especificado' => ['I64', 12],
            'sequelas de doença cerebrovascular' => ['I69', 12],
            'ataque isquêmico transitório' => ['G45', 12],
            'infarto agudo NÃO' => ['I21', null],
            'diabetes com cetoacidose' => ['E101', 13],
            'diabetes com complicações renais' => ['E112', 13],
            'diabetes sem complicações específicas (E10.9)' => ['E109', 13],
            'diabetes tipo não especificado sem complicações (E14.9)' => ['E149', 13],
            'diabetes E13' => ['E13', 13],
            'epilepsia' => ['G40', 14],
            // Grupos 15 a 19
            'nefrite túbulo-intersticial aguda' => ['N10', 15],
            'cistite' => ['N30', 15],
            'infecção urinária de localização NE' => ['N390', 15],
            'incontinência urinária N39.3 NÃO' => ['N393', null],
            'erisipela' => ['A46', 16],
            'celulite' => ['L03', 16],
            'impetigo' => ['L01', 16],
            'salpingite e ooforite' => ['N70', 17],
            'doença da glândula de Bartholin' => ['N75', 17],
            'N74 (não consta na lista)' => ['N74', null],
            'N74 com 4º caractere (não consta)' => ['N740', null],
            'úlcera gástrica' => ['K25', 18],
            'hemorragia gastrointestinal K92.0' => ['K920', 18],
            'melena K92.1' => ['K921', 18],
            'K92.2' => ['K922', 18],
            'K92.3 NÃO' => ['K923', null],
            'infecção urinária na gravidez' => ['O23', 19],
            'infecção urinária na gravidez com 4º caractere' => ['O234', 19],
            'sífilis congênita' => ['A50', 19],
            'síndrome da rubéola congênita' => ['P350', 19],
            'outra afecção perinatal P35.1 NÃO' => ['P351', null],
            // Fora da lista
            'parto normal' => ['O80', null],
            'sinais e sintomas' => ['R31', null],
            'fratura' => ['S628', null],
            'vazio' => ['', null],
            'curto demais' => ['A3', null],
        ];
    }

    #[DataProvider('diagnosticos')]
    public function test_classifica_diagnosticos_conforme_a_portaria(string $cid, ?int $grupoEsperado): void
    {
        $this->assertSame($grupoEsperado, $this->lista->grupoDe($cid));
        $this->assertSame($grupoEsperado !== null, $this->lista->contem($cid));
    }

    public function test_aceita_ponto_minusculas_e_espacos(): void
    {
        $this->assertSame(6, $this->lista->grupoDe('J15.3'));
        $this->assertSame(6, $this->lista->grupoDe('j153'));
        $this->assertSame(6, $this->lista->grupoDe('  J15.3 '));
        $this->assertSame(13, $this->lista->grupoDe('e10.9'));
    }
}
