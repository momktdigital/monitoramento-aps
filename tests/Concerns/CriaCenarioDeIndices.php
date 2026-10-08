<?php

namespace Tests\Concerns;

use App\Enums\QuadranteIpf;
use App\Models\IndiceMunicipio;
use App\Models\Metodologia;
use App\Models\Municipio;

/**
 * Seis municípios do RJ (duas regiões de saúde) com índices já calculados em jun e jul/2026, um em cada situação:
 *
 *   município       INA IDAPS quadrante               prioridade
 *   Macaé            90   10  prioridade máxima        90
 *   Valença          80   30  prioridade máxima        75   (estrutura 70, resultado 20: alerta de efetividade)
 *   Volta Redonda    40   20  oportunidade moderada    60
 *   Resende          70   70  grande potencial         50
 *   Cabo Frio        30   80  estrutura consolidada    25
 *   Niterói          20   90  estrutura consolidada    15
 *
 * Em jun/2026, o IDAPS de Valença era 25 (subiu 5 pontos).
 */
trait CriaCenarioDeIndices
{
    protected Metodologia $metodologia;

    /** @var array<string, Municipio> */
    protected array $municipiosDosIndices = [];

    protected function criarCenarioDeIndices(): void
    {
        $this->metodologia = Metodologia::factory()->create(['versao' => 1]);

        $cadastro = [
            'macae' => [3302403, 'Macaé', 33001, 'NORTE', 90, 10, QuadranteIpf::PrioridadeMaxima, 90.0],
            'valenca' => [3306107, 'Valença', 33004, 'MEDIO PARAIBA', 80, 30, QuadranteIpf::PrioridadeMaxima, 75.0],
            'volta_redonda' => [3306305, 'Volta Redonda', 33004, 'MEDIO PARAIBA', 40, 20, QuadranteIpf::OportunidadeModerada, 60.0],
            'resende' => [3304201, 'Resende', 33004, 'MEDIO PARAIBA', 70, 70, QuadranteIpf::GrandePotencial, 50.0],
            'cabo_frio' => [3300704, 'Cabo Frio', 33001, 'NORTE', 30, 80, QuadranteIpf::EstruturaConsolidada, 25.0],
            'niteroi' => [3303302, 'Niterói', 33001, 'NORTE', 20, 90, QuadranteIpf::EstruturaConsolidada, 15.0],
        ];

        foreach ($cadastro as $chave => [$id, $nome, $regiao, $regiaoNome, $ina, $idaps, $quadrante, $prioridade]) {
            $municipio = Municipio::factory()->create([
                'id' => $id,
                'codigo6' => $id % 1000000,
                'nome' => $nome,
                'nome_busca' => Municipio::normalizarNome($nome),
                'regiao_saude_codigo' => $regiao,
                'regiao_saude_nome' => $regiaoNome,
                'piloto' => $chave === 'valenca',
            ]);

            $this->municipiosDosIndices[$chave] = $municipio;

            $ehValenca = $chave === 'valenca';

            IndiceMunicipio::factory()->create([
                'municipio_id' => $id,
                'competencia' => 202607,
                'metodologia_id' => $this->metodologia->id,
                'ina' => $ina,
                'idaps' => $idaps,
                'idaps_estrutura' => $ehValenca ? 70 : $idaps,
                'idaps_resultado' => $ehValenca ? 20 : $idaps,
                'ipf_quadrante' => $quadrante,
                'ipf_pontuacao' => $prioridade,
                'estrutura_sem_resultado' => $ehValenca,
                'confianca_ina' => 1.0,
                'confianca_idaps' => 1.0,
                'detalhes' => ['idaps' => ['pilares' => [
                    'estrutura' => ['nota' => $ehValenca ? 70 : $idaps, 'completude' => 1],
                    'resultado' => ['nota' => $ehValenca ? 20 : $idaps, 'completude' => 1],
                ]]],
            ]);

            IndiceMunicipio::factory()->create([
                'municipio_id' => $id,
                'competencia' => 202606,
                'metodologia_id' => $this->metodologia->id,
                'ina' => $ina,
                'idaps' => $ehValenca ? 25 : $idaps,
                'idaps_estrutura' => $idaps,
                'idaps_resultado' => $idaps,
                'ipf_quadrante' => $quadrante,
                'ipf_pontuacao' => $prioridade,
                'estrutura_sem_resultado' => false,
                'confianca_ina' => 1.0,
                'confianca_idaps' => 1.0,
            ]);
        }
    }
}
