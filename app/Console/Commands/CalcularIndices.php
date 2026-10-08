<?php

namespace App\Console\Commands;

use App\Domain\Scoring\CalculadorDeIndices;
use App\Enums\QuadranteIpf;
use App\Models\Metodologia;
use App\Support\Competencia;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aps:calcular-indices')]
#[Description('Recalcula INA, IDAPS e IPF de todos os municípios com a metodologia de config/indices.php (cria nova versão se ela mudou)')]
class CalcularIndices extends Command
{
    public function handle(CalculadorDeIndices $calculador): int
    {
        $metodologia = Metodologia::sincronizar();

        $this->components->info("Metodologia versão {$metodologia->versao}");

        $resultado = $calculador->calcular($metodologia);

        foreach ($resultado['avisos'] as $aviso) {
            $this->components->warn($aviso);
        }

        $this->components->twoColumnDetail('Linhas gravadas (município × mês)', (string) $resultado['linhas']);
        $this->components->twoColumnDetail('Mês de referência mais recente', Competencia::rotulo($resultado['ultima_competencia']));

        foreach ($resultado['quadrantes'] as $quadrante => $quantidade) {
            $this->components->twoColumnDetail(QuadranteIpf::from($quadrante)->rotulo(), (string) $quantidade);
        }

        if ($resultado['linhas'] === 0) {
            $this->components->warn('Nenhum índice pôde ser calculado: faltam dados (veja as integrações) ou o pilar obrigatório não tem cobertura suficiente entre os municípios.');
        }

        return self::SUCCESS;
    }
}
