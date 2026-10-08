<?php

namespace App\Console\Commands;

use App\Enums\OrigemExecucao;
use App\Integrations\Ingestor;
use App\Integrations\RegistroDeConectores;
use App\Models\Integracao;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Helper\ProgressBar;

#[Signature('aps:ingerir {fonte? : ibge, egestor, dados_abertos ou transparencia} {--todas : Executa todas as fontes ativas, na ordem correta} {--meses= : Quantos meses retroativos buscar} {--piloto : Carga rápida: apenas os municípios marcados como piloto} {--fila : Apenas enfileira, em vez de executar agora}')]
#[Description('Atualiza os dados de uma fonte pública (ou de todas) e recalcula os benchmarks, mostrando o progresso')]
class IngerirFontes extends Command
{
    public function handle(Ingestor $ingestor): int
    {
        $integracoes = RegistroDeConectores::garantirIntegracoes()->keyBy('fonte');
        $meses = $this->option('meses') !== null ? (int) $this->option('meses') : null;

        if ($meses !== null && ($meses < 1 || $meses > 120)) {
            $this->components->error('Informe --meses entre 1 e 120.');

            return self::FAILURE;
        }

        if ($this->option('piloto') && $this->option('fila')) {
            $this->components->error('--piloto só funciona na execução direta (sem --fila).');

            return self::FAILURE;
        }

        if ($this->option('todas')) {
            $selecionadas = $integracoes->filter(fn (Integracao $integracao): bool => $integracao->ativa);
        } elseif ($this->argument('fonte') !== null) {
            try {
                RegistroDeConectores::para((string) $this->argument('fonte'));
            } catch (InvalidArgumentException) {
                $this->components->error('Fonte desconhecida. Disponíveis: '.$integracoes->keys()->implode(', ').'.');

                return self::FAILURE;
            }

            $selecionadas = $integracoes->only([$this->argument('fonte')]);
        } else {
            $this->components->error('Informe a fonte ou use --todas. Disponíveis: '.$integracoes->keys()->implode(', ').'.');

            return self::FAILURE;
        }

        $falhou = false;

        foreach ($selecionadas as $integracao) {
            if ($this->option('fila')) {
                $enfileirada = $ingestor->enfileirar($integracao, OrigemExecucao::Comando, meses: $meses);
                $this->components->twoColumnDetail($integracao->fonte, $enfileirada ? 'enfileirada' : 'já está em andamento');

                continue;
            }

            $falhou = ! $this->executarComProgresso($ingestor, $integracao, $meses) || $falhou;
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }

    private function executarComProgresso(Ingestor $ingestor, Integracao $integracao, ?int $meses): bool
    {
        $this->components->info("Atualizando {$integracao->fonte}".($this->option('piloto') ? ' (somente piloto)' : '').'…');

        $barra = null;
        $etapaAtual = null;

        $aoProgredir = function (int $feitos, ?int $total, ?string $etapa) use (&$barra, &$etapaAtual): void {
            if ($etapa !== $etapaAtual) {
                $this->encerrarBarra($barra);
                $etapaAtual = $etapa;
                $this->line("  <fg=cyan>{$etapa}</>");

                if ($total !== null) {
                    $barra = $this->output->createProgressBar($total);
                    $barra->setFormat('  %current%/%max% [%bar%] %percent:3s%%  decorrido %elapsed:6s%  restante ~%remaining:6s%');
                    $barra->start();
                }
            }

            if ($barra !== null && $total !== null) {
                $barra->setProgress(min($feitos, $total));
            } elseif ($total === null && $feitos > 0 && $feitos % 10 === 0) {
                $this->output->write("\r  {$feitos} páginas lidas");
            }
        };

        $ingestao = $ingestor->executar($integracao, OrigemExecucao::Comando, meses: $meses, apenasPiloto: (bool) $this->option('piloto'), aoProgredir: $aoProgredir);

        $this->encerrarBarra($barra);
        $this->newLine();
        $this->components->twoColumnDetail('Valores gravados', (string) $ingestao->linhas);
        $this->components->twoColumnDetail('Duração', ($ingestao->duracaoEmSegundos() ?? 0).' s');

        foreach (array_slice($ingestao->avisos ?? [], 0, 5) as $aviso) {
            $this->components->warn($aviso);
        }

        if ($ingestao->mensagem !== null) {
            $this->components->error($ingestao->mensagem);

            return false;
        }

        $this->components->info('Concluída.');

        return true;
    }

    private function encerrarBarra(?ProgressBar &$barra): void
    {
        if ($barra !== null) {
            $barra->finish();
            $this->newLine();
            $barra = null;
        }
    }
}
