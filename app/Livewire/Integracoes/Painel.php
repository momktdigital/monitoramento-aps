<?php

namespace App\Livewire\Integracoes;

use App\Enums\Frequencia;
use App\Enums\OrigemExecucao;
use App\Enums\StatusIngestao;
use App\Enums\StatusIntegracao;
use App\Integrations\CampoDeConfiguracao;
use App\Integrations\Contracts\ConectorDeFonte;
use App\Integrations\Ingestor;
use App\Integrations\RegistroDeConectores;
use App\Models\Ingestao;
use App\Models\Integracao;
use App\Support\Auditoria;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('Integrações')]
class Painel extends Component
{
    private const LIMITE_DE_TESTES_POR_MINUTO = 6;

    private const LIMITE_DE_EXECUCOES_POR_HORA = 10;

    /** @var 'configurar'|'atualizar'|'historico'|null */
    #[Locked]
    public ?string $janela = null;

    #[Locked]
    public ?int $integracaoId = null;

    public string $frequencia = 'diaria';

    public string $horario = '03:00';

    public bool $ativa = true;

    /** @var array<string, string> */
    public array $valores = [];

    public string $periodo = 'padrao';

    /** @var array<int, array{ok: bool, texto: string}> */
    public array $resultadosDeTeste = [];

    public function mount(): void
    {
        Gate::authorize('gerenciar-integracoes');

        RegistroDeConectores::garantirIntegracoes();
    }

    public function abrirConfiguracao(int $id): void
    {
        $integracao = $this->integracaoAutorizada($id);

        $this->janela = 'configurar';
        $this->integracaoId = $integracao->id;
        $this->frequencia = $integracao->frequencia->value;
        $this->horario = substr((string) $integracao->horario, 0, 5);
        $this->ativa = $integracao->ativa;
        $this->valores = [];

        foreach ($integracao->conector()->campos() as $campo) {
            $this->valores[$campo->nome] = $campo->secreto
                ? ''
                : (string) ($integracao->valorDeConfig($campo->nome) ?? $campo->padrao ?? '');
        }

        $this->resetErrorBag();
    }

    public function abrirAtualizacao(int $id): void
    {
        $this->janela = 'atualizar';
        $this->integracaoId = $this->integracaoAutorizada($id)->id;
        $this->periodo = 'padrao';
    }

    public function abrirHistorico(int $id): void
    {
        $this->janela = 'historico';
        $this->integracaoId = $this->integracaoAutorizada($id)->id;
    }

    public function fechar(): void
    {
        $this->reset('janela', 'integracaoId', 'valores', 'periodo');
        $this->resetErrorBag();
    }

    public function salvarConfiguracao(): void
    {
        $integracao = $this->integracaoAutorizada($this->integracaoId);
        $campos = collect($integracao->conector()->campos())->keyBy('nome');

        $regras = [
            'frequencia' => ['required', Rule::enum(Frequencia::class)],
            'horario' => ['required', 'date_format:H:i'],
            'ativa' => ['boolean'],
        ];

        foreach ($campos as $nome => $campo) {
            $regras["valores.{$nome}"] = $campo->regras();
        }

        $this->validate($regras, [], [
            'frequencia' => 'frequência',
            'horario' => 'horário',
            ...$campos->mapWithKeys(fn (CampoDeConfiguracao $campo, string $nome): array => ["valores.{$nome}" => mb_strtolower($campo->rotulo)])->all(),
        ]);

        $config = $integracao->config ?? [];
        $camposAlterados = [];

        foreach ($campos as $nome => $campo) {
            $informado = trim($this->valores[$nome] ?? '');

            if ($campo->secreto) {
                if ($informado !== '') {
                    $config[$nome] = $informado;
                    $camposAlterados[] = $nome;
                }

                continue;
            }

            if (($config[$nome] ?? null) !== ($informado === '' ? null : $informado)) {
                $camposAlterados[] = $nome;
            }

            if ($informado === '') {
                unset($config[$nome]);
            } else {
                $config[$nome] = $informado;
            }
        }

        $integracao->forceFill([
            'frequencia' => $this->frequencia,
            'horario' => $this->horario.':00',
            'ativa' => $this->ativa,
            'config' => $config === [] ? null : $config,
        ]);

        $ativadaAutomaticamente = $this->ativarSeConfigurada($integracao);

        $integracao->save();

        Auditoria::registrar('integracao_configurada', [
            'fonte' => $integracao->fonte,
            'campos_alterados' => $camposAlterados,
            'frequencia' => $this->frequencia,
            'ativa' => $integracao->ativa,
        ]);

        session()->flash('sucesso', 'Configuração salva.'.($ativadaAutomaticamente ? ' A integração foi ativada.' : ''));

        $this->fechar();
    }

    public function alternarAtiva(int $id): void
    {
        $integracao = $this->integracaoAutorizada($id);

        $integracao->forceFill(['ativa' => ! $integracao->ativa])->save();

        Auditoria::registrar('integracao_'.($integracao->ativa ? 'retomada' : 'pausada'), ['fonte' => $integracao->fonte]);
    }

    public function testar(int $id): void
    {
        $integracao = $this->integracaoAutorizada($id);
        $chave = 'integracao-teste:'.Auth::id();

        if (RateLimiter::tooManyAttempts($chave, self::LIMITE_DE_TESTES_POR_MINUTO)) {
            $this->resultadosDeTeste[$id] = ['ok' => false, 'texto' => 'Muitos testes seguidos. Aguarde um minuto.'];

            return;
        }

        RateLimiter::hit($chave, 60);

        $resultado = $integracao->conector()->testarConexao($integracao);
        $this->resultadosDeTeste[$id] = ['ok' => $resultado->ok, 'texto' => $resultado->mensagem];

        Auditoria::registrar('integracao_testada', ['fonte' => $integracao->fonte, 'ok' => $resultado->ok]);
    }

    public function atualizarAgora(Ingestor $ingestor): void
    {
        $integracao = $this->integracaoAutorizada($this->integracaoId);

        $this->validate(['periodo' => ['required', Rule::in(['padrao', '12', '36'])]]);

        if ($integracao->conector()->exigeConfiguracao() && ! $this->configuracaoCompleta($integracao)) {
            $this->addError('periodo', 'Configure os parâmetros obrigatórios (como a chave da API) antes de atualizar.');

            return;
        }

        $resultado = RateLimiter::attempt(
            'integracao-forcar:'.Auth::id(),
            self::LIMITE_DE_EXECUCOES_POR_HORA,
            fn (): string => $ingestor->enfileirar($integracao, OrigemExecucao::Manual, Auth::id(), $this->periodo === 'padrao' ? null : (int) $this->periodo)
                ? 'enfileirada'
                : 'em_andamento',
            3600,
        );

        if ($resultado === false) {
            $this->addError('periodo', 'Limite de atualizações manuais por hora atingido. Tente mais tarde.');

            return;
        }

        if ($resultado === 'enfileirada') {
            Auditoria::registrar('integracao_execucao_forcada', ['fonte' => $integracao->fonte, 'periodo' => $this->periodo]);
            session()->flash('sucesso', 'Atualização colocada na fila. O processamento começa assim que o processador de filas estiver livre.');
        } else {
            session()->flash('aviso', 'Esta integração já está em andamento.');
        }

        $this->fechar();
    }

    public function render(): View
    {
        Gate::authorize('gerenciar-integracoes');

        $agora = Date::now();
        $integracoes = RegistroDeConectores::garantirIntegracoes()->map(fn (Integracao $integracao): array => $this->resumo($integracao, $agora));

        return view('livewire.integracoes.painel', [
            'agora' => $agora,
            'execucoes' => Ingestao::whereIn('integracao_id', $integracoes->map(fn (array $item): int => $item['integracao']->id))
                ->where('status', StatusIngestao::Executando->value)
                ->get()
                ->keyBy('integracao_id'),
            'integracoes' => $integracoes,
            'algumaEmAndamento' => $integracoes->contains(fn (array $item): bool => $item['integracao']->status->emAndamento()),
            'filaParada' => $integracoes->contains(fn (array $item): bool => $item['integracao']->status === StatusIntegracao::NaFila
                && $item['integracao']->updated_at->lessThan($agora->copy()->subMinutes(2))),
            'selecionada' => $this->integracaoId === null ? null : $integracoes->firstWhere(fn (array $item): bool => $item['integracao']->id === $this->integracaoId),
            'historico' => $this->janela === 'historico' && $this->integracaoId !== null
                ? Integracao::findOrFail($this->integracaoId)->ingestoes()->with('user:id,name')->latest('iniciada_em')->limit(15)->get()
                : collect(),
        ]);
    }

    /**
     * @return array{integracao: Integracao, conector: ConectorDeFonte, estado: array{rotulo: string, cor: string}, configurada: bool, proximo: ?string}
     */
    private function resumo(Integracao $integracao, CarbonInterface $agora): array
    {
        $conector = $integracao->conector();
        $configurada = ! $conector->exigeConfiguracao() || $this->configuracaoCompleta($integracao);

        return [
            'integracao' => $integracao,
            'conector' => $conector,
            'estado' => $this->estado($integracao, $configurada, $agora),
            'configurada' => $configurada,
            'proximo' => $integracao->ativa ? $integracao->proximoHorario($agora)?->translatedFormat('d/m/Y \à\s H:i') : null,
        ];
    }

    /**
     * @return array{rotulo: string, cor: string}
     */
    private function estado(Integracao $integracao, bool $configurada, CarbonInterface $agora): array
    {
        $previsto = $integracao->ultimoHorarioPrevisto($agora);
        $atrasada = $integracao->ativa && $previsto !== null && $integracao->estaVencida($agora) && $previsto->lessThan($agora->copy()->subHours(2));

        return match (true) {
            ! $configurada => ['rotulo' => 'Configuração pendente', 'cor' => 'ambar'],
            ! $integracao->ativa => ['rotulo' => 'Pausada', 'cor' => 'cinza'],
            $integracao->status === StatusIntegracao::Executando => ['rotulo' => 'Atualizando…', 'cor' => 'azul'],
            $integracao->status === StatusIntegracao::NaFila => ['rotulo' => 'Na fila', 'cor' => 'azul'],
            $integracao->status === StatusIntegracao::Erro => ['rotulo' => 'Falhou', 'cor' => 'vermelho'],
            $integracao->status === StatusIntegracao::Nunca => ['rotulo' => 'Nunca executada', 'cor' => 'cinza'],
            $atrasada => ['rotulo' => 'Atrasada', 'cor' => 'ambar'],
            default => ['rotulo' => 'Atualizada', 'cor' => 'verde'],
        };
    }

    private function integracaoAutorizada(?int $id): Integracao
    {
        Gate::authorize('gerenciar-integracoes');

        return Integracao::findOrFail($id);
    }

    private function configuracaoCompleta(Integracao $integracao): bool
    {
        return collect($integracao->conector()->campos())
            ->filter(fn (CampoDeConfiguracao $campo): bool => $campo->obrigatorio)
            ->every(fn (CampoDeConfiguracao $campo): bool => $integracao->possuiConfig($campo->nome));
    }

    /**
     * Integrações que exigem chave nascem pausadas: ao completar a configuração pela primeira vez, ativa.
     */
    private function ativarSeConfigurada(Integracao $integracao): bool
    {
        if ($integracao->ativa || $integracao->ultima_execucao_em !== null || ! $integracao->conector()->exigeConfiguracao()) {
            return false;
        }

        if (! $this->configuracaoCompleta($integracao)) {
            return false;
        }

        $integracao->ativa = true;

        return true;
    }
}
