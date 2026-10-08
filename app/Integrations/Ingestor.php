<?php

namespace App\Integrations;

use App\Domain\Indicators\CalculadorDeBenchmarks;
use App\Domain\Scoring\CalculadorDeIndices;
use App\Enums\OrigemExecucao;
use App\Enums\StatusIngestao;
use App\Enums\StatusIntegracao;
use App\Jobs\IngerirFonte;
use App\Models\Ingestao;
use App\Models\Integracao;
use App\Support\Auditoria;
use App\Support\VersaoDosDados;
use Closure;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Executa uma integração do início ao fim: registra a execução, roda o conector, calcula os
 * benchmarks, renova a versão dos dados e atualiza o estado da integração.
 */
class Ingestor
{
    private const TAMANHO_MAXIMO_DA_MENSAGEM = 1000;

    public function __construct(
        private readonly CalculadorDeBenchmarks $benchmarks,
        private readonly CalculadorDeIndices $indices,
    ) {}

    /**
     * Coloca a execução na fila. Retorna false se já houver uma em andamento (e não travada).
     */
    public function enfileirar(Integracao $integracao, OrigemExecucao $origem, ?int $userId = null, ?int $meses = null): bool
    {
        if ($integracao->status->emAndamento() && ! $integracao->estaTravada(Date::now())) {
            return false;
        }

        $integracao->forceFill(['status' => StatusIntegracao::NaFila])->save();

        IngerirFonte::dispatch($integracao->id, $origem, $userId, $meses);

        return true;
    }

    /**
     * @param  Closure(int, ?int, ?string): void|null  $aoProgredir  recebe (concluídos, total, etapa) a cada avanço
     */
    public function executar(Integracao $integracao, OrigemExecucao $origem, ?int $userId = null, ?int $meses = null, bool $apenasPiloto = false, ?Closure $aoProgredir = null): Ingestao
    {
        $agora = Date::now();

        $ingestao = Ingestao::create([
            'integracao_id' => $integracao->id,
            'user_id' => $userId,
            'origem' => $origem,
            'status' => StatusIngestao::Executando,
            'meses' => $meses,
            'iniciada_em' => $agora,
        ]);

        $integracao->forceFill(['status' => StatusIntegracao::Executando, 'ultima_execucao_em' => $agora])->save();

        $contexto = new ContextoDeIngestao($integracao, $ingestao, $agora, $meses, $apenasPiloto);

        if ($aoProgredir !== null) {
            $contexto->ouvirProgresso($aoProgredir);
        }
        $erro = null;

        try {
            $integracao->conector()->executar($contexto);
        } catch (Throwable $e) {
            $erro = $e;

            if (! $e instanceof ConfiguracaoInvalida) {
                report($e);
            }
        }

        $erroAoGravar = $this->finalizarContexto($contexto);
        $erro ??= $erroAoGravar;

        $this->fecharExecucao($integracao, $ingestao, $contexto, $erro, $userId);

        return $ingestao;
    }

    private function finalizarContexto(ContextoDeIngestao $contexto): ?Throwable
    {
        try {
            $contexto->finalizar();

            if ($contexto->linhas() > 0) {
                $this->benchmarks->calcular($contexto->competenciasAfetadas());
                VersaoDosDados::renovar();
                $this->recalcularIndices($contexto);
            }
        } catch (Throwable $e) {
            report($e);

            return $e;
        }

        return null;
    }

    /**
     * Recalcula INA, IDAPS e IPF quando a carga mexeu em algum indicador que eles usam. Uma falha aqui não
     * invalida a carga (os dados já estão salvos): vira aviso na execução.
     */
    private function recalcularIndices(ContextoDeIngestao $contexto): void
    {
        try {
            if ($this->indices->afetadoPor(array_keys($contexto->competenciasAfetadas()))) {
                $this->indices->calcular();
            }
        } catch (Throwable $e) {
            report($e);
            $contexto->aviso('Os dados foram salvos, mas os índices (INA, IDAPS e IPF) não puderam ser recalculados. Rode "php artisan aps:calcular-indices" e consulte o log.');
        }
    }

    private function fecharExecucao(Integracao $integracao, Ingestao $ingestao, ContextoDeIngestao $contexto, ?Throwable $erro, ?int $userId): void
    {
        $status = match (true) {
            $erro !== null => StatusIngestao::Erro,
            $contexto->avisos() !== [] => StatusIngestao::Parcial,
            default => StatusIngestao::Sucesso,
        };

        $mensagem = $erro !== null ? $this->mensagemSegura($erro, $integracao) : null;

        $ingestao->update([
            'status' => $status,
            'finalizada_em' => Date::now(),
            'linhas' => $contexto->linhas(),
            'competencia_mais_recente' => $contexto->competenciaMaisRecente(),
            'mensagem' => $mensagem,
            'avisos' => $contexto->avisos() ?: null,
        ]);

        $atualizacao = [
            'status' => $erro !== null ? StatusIntegracao::Erro : StatusIntegracao::Ok,
            'ultimo_erro' => $mensagem,
        ];

        if ($erro === null) {
            $atualizacao += [
                'ultimo_sucesso_em' => $ingestao->finalizada_em,
                'ultimas_linhas' => $contexto->linhas(),
                'ultima_competencia' => $contexto->competenciaMaisRecente() ?? $integracao->ultima_competencia,
            ];
        }

        $integracao->forceFill($atualizacao)->save();

        Auditoria::registrar('integracao_executada', [
            'fonte' => $integracao->fonte,
            'origem' => $ingestao->origem->value,
            'status' => $status->value,
            'linhas' => $contexto->linhas(),
        ], $userId);
    }

    /**
     * Mensagem de erro para exibir na tela: sem segredos e com tamanho limitado.
     */
    private function mensagemSegura(Throwable $erro, Integracao $integracao): string
    {
        $mensagem = $erro->getMessage();

        foreach ($integracao->config ?? [] as $chave => $valor) {
            if (is_string($valor) && strlen($valor) >= 6) {
                $mensagem = str_replace($valor, '***', $mensagem);
            }
        }

        return mb_substr(trim($mensagem) ?: class_basename($erro), 0, self::TAMANHO_MAXIMO_DA_MENSAGEM);
    }
}
