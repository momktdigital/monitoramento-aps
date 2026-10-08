<?php

namespace App\Console\Commands;

use App\Enums\StatusIngestao;
use App\Enums\StatusIntegracao;
use App\Models\Ingestao;
use App\Models\Integracao;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aps:destravar {fonte? : Limita a uma fonte (ibge, egestor, dados_abertos, transparencia)}')]
#[Description('Marca como interrompidas as execuções "na fila" ou "em andamento" cujo processo foi encerrado, liberando o botão Atualizar agora')]
class DestravarIntegracoes extends Command
{
    public function handle(): int
    {
        $integracoes = Integracao::query()
            ->whereIn('status', [StatusIntegracao::NaFila->value, StatusIntegracao::Executando->value])
            ->when($this->argument('fonte'), fn ($consulta, string $fonte) => $consulta->where('fonte', $fonte))
            ->get();

        if ($integracoes->isEmpty()) {
            $this->components->info('Nenhuma integração presa.');

            return self::SUCCESS;
        }

        $mensagem = 'Execução interrompida manualmente (o processador de filas foi encerrado antes de terminar).';

        foreach ($integracoes as $integracao) {
            Ingestao::where('integracao_id', $integracao->id)
                ->where('status', StatusIngestao::Executando->value)
                ->update(['status' => StatusIngestao::Erro->value, 'finalizada_em' => now(), 'mensagem' => $mensagem]);

            $integracao->forceFill(['status' => StatusIntegracao::Erro, 'ultimo_erro' => $mensagem])->save();

            $this->components->twoColumnDetail($integracao->fonte, 'liberada');
        }

        $this->components->warn('Se um processador de filas ainda estiver rodando essas execuções, encerre-o antes: o resultado dele sobrescreveria este estado.');

        return self::SUCCESS;
    }
}
