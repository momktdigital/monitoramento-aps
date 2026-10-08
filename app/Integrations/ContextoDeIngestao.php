<?php

namespace App\Integrations;

use App\Models\Indicador;
use App\Models\Ingestao;
use App\Models\Integracao;
use App\Models\Municipio;
use App\Models\ValorIndicador;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tudo que um conector precisa durante uma execução: municípios, janela de competências,
 * gravação em lote (idempotente) e registro de avisos.
 */
class ContextoDeIngestao
{
    private const TAMANHO_DO_LOTE = 1000;

    private const MAXIMO_DE_AVISOS = 50;

    /** @var array<string, int> código do indicador ativo => id */
    private array $indicadoresAtivos;

    /** @var Collection<int, Municipio>|null */
    private ?Collection $municipios = null;

    private ?PopulacaoDeReferencia $populacao = null;

    /** @var list<array<string, mixed>> */
    private array $lote = [];

    /** @var array<int, array<int, true>> indicador_id => [competência => true] */
    private array $afetadas = [];

    private int $linhas = 0;

    private ?int $competenciaMaisRecente = null;

    /** @var list<string> */
    private array $avisos = [];

    private ?Closure $ouvinteDeProgresso = null;

    private ?string $etapa = null;

    private ?int $passosTotal = null;

    private int $passosConcluidos = 0;

    private float $ultimaPublicacaoDoProgresso = 0.0;

    public function __construct(
        public readonly Integracao $integracao,
        public readonly Ingestao $ingestao,
        public readonly CarbonInterface $agora,
        private readonly ?int $mesesSolicitados = null,
        private readonly bool $apenasPiloto = false,
    ) {
        $this->indicadoresAtivos = Indicador::ativo()->pluck('id', 'codigo')->all();
    }

    /**
     * Quantidade de meses a buscar: o pedido na execução manual; na primeira carga, o histórico
     * configurado; depois, só os últimos meses (a fonte pode revisar dados recentes).
     */
    public function meses(): int
    {
        if ($this->mesesSolicitados !== null) {
            return max(1, $this->mesesSolicitados);
        }

        if ($this->integracao->ultimo_sucesso_em === null) {
            return max(1, (int) $this->integracao->valorDeConfig('meses_historico', 36));
        }

        return $this->integracao->conector()->mesesDeAtualizacao();
    }

    /**
     * @return list<int> competências AAAAMM em ordem crescente, do mês inicial até o mês atual
     */
    public function competencias(): array
    {
        $competencias = [];
        $mes = $this->agora->copy()->startOfMonth()->subMonthsNoOverflow($this->meses() - 1);

        while ($mes->lessThanOrEqualTo($this->agora)) {
            $competencias[] = (int) $mes->format('Ym');
            $mes = $mes->addMonthNoOverflow();
        }

        return $competencias;
    }

    public function competenciaAtual(): int
    {
        return (int) $this->agora->format('Ym');
    }

    /**
     * @return Collection<int, Municipio>
     */
    public function municipios(): Collection
    {
        return $this->municipios ??= Municipio::ativo()
            ->whereIn('uf', config('aps.ufs'))
            ->when($this->apenasPiloto, fn ($consulta) => $consulta->piloto())
            ->orderByDesc('piloto')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<int> códigos numéricos das UFs carregadas
     */
    public function codigosDeUf(): array
    {
        return $this->municipios()->pluck('codigo_uf')->unique()->sort()->values()->all();
    }

    /**
     * @return list<string> siglas das UFs carregadas (ex.: RJ)
     */
    public function ufs(): array
    {
        return $this->municipios()->pluck('uf')->unique()->sort()->values()->all();
    }

    /**
     * @return array<string, int> código IBGE de 6 dígitos (padrão do DATASUS) => id do município
     */
    public function municipiosPorCodigoDeSeisDigitos(): array
    {
        return $this->municipios()->pluck('id', 'codigo6')->mapWithKeys(fn (int $id, int|string $codigo): array => [(string) $codigo => $id])->all();
    }

    public function idDoIndicador(string $codigo): ?int
    {
        return $this->indicadoresAtivos[$codigo] ?? null;
    }

    /**
     * Execução pedida de propósito (botão "Atualizar agora" ou --meses): reprocessa tudo, sem pular
     * arquivos que não mudaram desde a última leitura.
     */
    public function ehReprocessamento(): bool
    {
        return $this->mesesSolicitados !== null;
    }

    /**
     * Grava no banco o que está na fila, para que consultas seguintes (ex.: somas de 12 meses) enxerguem os valores.
     */
    public function persistirPendentes(): void
    {
        $this->descarregar();
    }

    public function populacao(): PopulacaoDeReferencia
    {
        return $this->populacao ??= new PopulacaoDeReferencia;
    }

    public function indicadorAtivo(string $codigo): bool
    {
        return isset($this->indicadoresAtivos[$codigo]);
    }

    /**
     * Enfileira um valor para gravação. Indicadores desativados ou desconhecidos são ignorados.
     */
    public function gravar(string $codigoDoIndicador, int $municipioId, int $competencia, ?float $valor, ?float $numerador = null, ?float $denominador = null): void
    {
        $indicadorId = $this->indicadoresAtivos[$codigoDoIndicador] ?? null;

        if ($indicadorId === null || ($valor !== null && ! is_finite($valor))) {
            return;
        }

        $this->lote[] = [
            'municipio_id' => $municipioId,
            'indicador_id' => $indicadorId,
            'competencia' => $competencia,
            'valor' => $valor === null ? null : round($valor, 4),
            'numerador' => $numerador === null ? null : round($numerador, 2),
            'denominador' => $denominador === null ? null : round($denominador, 2),
        ];

        $this->afetadas[$indicadorId][$competencia] = true;
        $this->linhas++;
        $this->competenciaMaisRecente = max($this->competenciaMaisRecente ?? 0, $competencia);

        if (count($this->lote) >= self::TAMANHO_DO_LOTE) {
            $this->descarregar();
        }
    }

    public function aviso(string $mensagem): void
    {
        if (count($this->avisos) < self::MAXIMO_DE_AVISOS) {
            $this->avisos[] = $mensagem;
        } elseif (count($this->avisos) === self::MAXIMO_DE_AVISOS) {
            $this->avisos[] = 'Há mais avisos além destes (lista truncada).';
        }
    }

    /**
     * Pausa curta entre requisições para não sobrecarregar as fontes públicas.
     */
    public function pausar(?int $milissegundos = null): void
    {
        $milissegundos ??= (int) config('aps.http.pausa_ms');

        if ($milissegundos > 0) {
            usleep($milissegundos * 1000);
        }
    }

    /**
     * Informa em que etapa a execução está e, se souber, quantos passos ela tem. Alimenta a barra de progresso.
     */
    public function etapa(string $descricao, ?int $totalDePassos = null): void
    {
        $this->etapa = $descricao;
        $this->passosTotal = $totalDePassos;
        $this->passosConcluidos = 0;
        $this->publicarProgresso(true);
    }

    public function avancar(int $passos = 1): void
    {
        $this->passosConcluidos += $passos;
        $this->publicarProgresso(false);
    }

    /**
     * Recebe (concluídos, total|null, etapa) a cada avanço. Usado pelo comando de terminal.
     */
    public function ouvirProgresso(Closure $ouvinte): void
    {
        $this->ouvinteDeProgresso = $ouvinte;
    }

    public function finalizar(): void
    {
        $this->descarregar();

        if ($this->passosTotal !== null) {
            $this->passosConcluidos = $this->passosTotal;
        }

        $this->publicarProgresso(true);
    }

    public function linhas(): int
    {
        return $this->linhas;
    }

    public function competenciaMaisRecente(): ?int
    {
        return $this->competenciaMaisRecente;
    }

    /**
     * @return list<string>
     */
    public function avisos(): array
    {
        return $this->avisos;
    }

    /**
     * @return array<int, list<int>> indicador_id => competências gravadas nesta execução
     */
    public function competenciasAfetadas(): array
    {
        return array_map(fn (array $competencias): array => array_keys($competencias), $this->afetadas);
    }

    /**
     * Grava o progresso no máximo uma vez por segundo. A gravação também renova o "sinal de vida" da integração,
     * que é o que distingue uma carga longa de um processo morto.
     */
    private function publicarProgresso(bool $forcar): void
    {
        if ($this->ouvinteDeProgresso !== null) {
            ($this->ouvinteDeProgresso)($this->passosConcluidos, $this->passosTotal, $this->etapa);
        }

        $agora = microtime(true);

        if (! $forcar && $agora - $this->ultimaPublicacaoDoProgresso < 1.0) {
            return;
        }

        $this->ultimaPublicacaoDoProgresso = $agora;

        Ingestao::whereKey($this->ingestao->getKey())->update([
            'etapa' => $this->etapa,
            'passos_total' => $this->passosTotal,
            'passos_concluidos' => $this->passosConcluidos,
        ]);

        Integracao::whereKey($this->integracao->getKey())->update(['updated_at' => now()]);
    }

    private function descarregar(): void
    {
        if ($this->lote === []) {
            return;
        }

        ValorIndicador::gravarEmLote($this->lote);
        $this->lote = [];
    }
}
