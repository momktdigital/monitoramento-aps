<?php

namespace Tests\Concerns;

use App\Domain\Indicators\CalculadorDeBenchmarks;
use App\Enums\Dimensao;
use App\Enums\Periodicidade;
use App\Enums\Polaridade;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Models\ValorIndicador;

/**
 * Cenário comum dos testes do painel: 3 municípios na região de saúde 33004 (Valença, Resende e
 * Volta Redonda) e 1 em outra região (Cabo Frio), com indicadores de três polaridades e benchmarks calculados.
 */
trait CriaCenarioDoPainel
{
    protected Municipio $valenca;

    protected Municipio $resende;

    protected Municipio $voltaRedonda;

    protected Municipio $caboFrio;

    protected Indicador $coberturaEsf;

    protected Indicador $icsap;

    protected Indicador $populacao;

    protected function criarCenarioDoPainel(): void
    {
        $this->valenca = Municipio::factory()->create(['id' => 3306107, 'codigo6' => 330610, 'nome' => 'Valença', 'nome_busca' => 'valenca', 'regiao_saude_codigo' => 33004, 'regiao_saude_nome' => 'MEDIO PARAIBA', 'piloto' => true]);
        $this->resende = Municipio::factory()->create(['id' => 3304201, 'codigo6' => 330420, 'nome' => 'Resende', 'nome_busca' => 'resende', 'regiao_saude_codigo' => 33004, 'regiao_saude_nome' => 'MEDIO PARAIBA']);
        $this->voltaRedonda = Municipio::factory()->create(['id' => 3306305, 'codigo6' => 330630, 'nome' => 'Volta Redonda', 'nome_busca' => 'volta redonda', 'regiao_saude_codigo' => 33004, 'regiao_saude_nome' => 'MEDIO PARAIBA']);
        $this->caboFrio = Municipio::factory()->create(['id' => 3300704, 'codigo6' => 330070, 'nome' => 'Cabo Frio', 'nome_busca' => 'cabo frio', 'regiao_saude_codigo' => 33001, 'regiao_saude_nome' => 'BAIXADA LITORANEA']);

        $this->coberturaEsf = Indicador::factory()->create([
            'codigo' => 'cobertura_esf', 'nome' => 'Cobertura da Estratégia Saúde da Família', 'dimensao' => Dimensao::Desempenho,
            'fonte' => 'egestor', 'unidade' => '%', 'polaridade' => Polaridade::MaiorMelhor, 'periodicidade' => Periodicidade::Mensal, 'casas_decimais' => 1, 'ordem' => 1,
            'o_que_e' => 'Parcela da população coberta por equipes de Saúde da Família.',
        ]);
        $this->icsap = Indicador::factory()->create([
            'codigo' => 'icsap_taxa', 'nome' => 'Internações por condições sensíveis à APS', 'dimensao' => Dimensao::Desempenho,
            'fonte' => 'sih', 'unidade' => 'por 10 mil hab.', 'polaridade' => Polaridade::MenorMelhor, 'periodicidade' => Periodicidade::Mensal, 'casas_decimais' => 1, 'ordem' => 2,
        ]);
        $this->populacao = Indicador::factory()->create([
            'codigo' => 'populacao_total', 'nome' => 'População residente', 'dimensao' => Dimensao::Necessidade,
            'fonte' => 'ibge', 'unidade' => 'pessoas', 'polaridade' => Polaridade::Neutra, 'periodicidade' => Periodicidade::Anual, 'casas_decimais' => 0, 'ordem' => 3,
        ]);

        // Cobertura da ESF: Valença sobe de 80 (jul/2025) até 88 (jul/2026); região e estado terminam abaixo dela.
        $this->gravar($this->coberturaEsf, $this->valenca, [202507 => 80.0, 202601 => 81.0, 202602 => 82.0, 202603 => 83.0, 202604 => 84.0, 202605 => 85.0, 202606 => 86.0, 202607 => 88.0]);
        $this->gravar($this->coberturaEsf, $this->resende, [202507 => 72.0, 202607 => 70.0]);
        $this->gravar($this->coberturaEsf, $this->voltaRedonda, [202507 => 74.0, 202607 => 75.0]);
        $this->gravar($this->coberturaEsf, $this->caboFrio, [202507 => 61.0, 202607 => 60.0]);

        $this->gravar($this->icsap, $this->valenca, [202607 => 30.0]);
        $this->gravar($this->populacao, $this->valenca, [202212 => 68088.0, 202412 => 71462.0, 202512 => 71449.0]);

        app(CalculadorDeBenchmarks::class)->recalcularTudo();
    }

    /**
     * @param  array<int, float>  $valoresPorCompetencia
     */
    protected function gravar(Indicador $indicador, Municipio $municipio, array $valoresPorCompetencia): void
    {
        ValorIndicador::gravarEmLote(array_map(
            fn (int $competencia, float $valor): array => ['municipio_id' => $municipio->id, 'indicador_id' => $indicador->id, 'competencia' => $competencia, 'valor' => $valor],
            array_keys($valoresPorCompetencia),
            $valoresPorCompetencia,
        ));
    }
}
