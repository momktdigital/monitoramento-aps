<?php

namespace App\Jobs;

use App\Enums\OrigemExecucao;
use App\Enums\StatusIngestao;
use App\Enums\StatusIntegracao;
use App\Integrations\Ingestor;
use App\Models\Ingestao;
use App\Models\Integracao;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class IngerirFonte implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 7200;

    public int $uniqueFor = 7200;

    public function __construct(
        public readonly int $integracaoId,
        public readonly OrigemExecucao $origem = OrigemExecucao::Agendada,
        public readonly ?int $userId = null,
        public readonly ?int $meses = null,
    ) {}

    /**
     * Uma execução por fonte por vez: cliques repetidos ou agendamentos sobrepostos não duplicam trabalho.
     */
    public function uniqueId(): string
    {
        return 'ingerir-fonte-'.$this->integracaoId;
    }

    public function handle(Ingestor $ingestor): void
    {
        $integracao = Integracao::find($this->integracaoId);

        if ($integracao === null || (! $integracao->ativa && $this->origem === OrigemExecucao::Agendada)) {
            return;
        }

        $ingestor->executar($integracao, $this->origem, $this->userId, $this->meses);
    }

    /**
     * Chamado quando o processo morre (tempo esgotado, falta de memória): evita ficar "atualizando" para sempre.
     */
    public function failed(Throwable $exception): void
    {
        Ingestao::where('integracao_id', $this->integracaoId)
            ->where('status', StatusIngestao::Executando->value)
            ->update([
                'status' => StatusIngestao::Erro->value,
                'finalizada_em' => now(),
                'mensagem' => mb_substr('A execução foi interrompida: '.class_basename($exception), 0, 255),
            ]);

        Integracao::whereKey($this->integracaoId)->update([
            'status' => StatusIntegracao::Erro->value,
            'ultimo_erro' => mb_substr('A execução foi interrompida: '.class_basename($exception), 0, 255),
            'updated_at' => now(),
        ]);
    }
}
