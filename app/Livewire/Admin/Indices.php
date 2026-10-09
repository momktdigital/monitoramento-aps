<?php

namespace App\Livewire\Admin;

use App\Administracao\VerificadorDeSaude;
use App\Jobs\RecalcularIndices;
use App\Livewire\Concerns\ConfirmaSenhaDoAdministrador;
use App\Models\IndiceMunicipio;
use App\Models\Metodologia;
use App\Support\Auditoria;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('Índices — Administração')]
class Indices extends Component
{
    use ConfirmaSenhaDoAdministrador;

    private const INDICES = ['ina' => 'INA — Índice de Necessidade da APS', 'idaps' => 'IDAPS — Índice de Desempenho da APS'];

    private const PESO_MAXIMO = 100;

    /** @var 'pesos'|'arquivo'|null */
    #[Locked]
    public ?string $janela = null;

    /** @var array<string, array<string, int|float|string>> índice => pilar => peso do pilar */
    public array $pilares = [];

    /** @var array<string, array<string, array<string, int|float|string>>> índice => pilar => indicador => peso */
    public array $componentes = [];

    public function mount(): void
    {
        Gate::authorize('administrar');
        $this->carregarPesos();
    }

    public function hydrate(): void
    {
        Gate::authorize('administrar');
    }

    public function editarPesos(): void
    {
        $this->carregarPesos();
        $this->senhaAtual = '';
        $this->resetErrorBag();
        $this->janela = 'pesos';
    }

    public function pedirVoltaAoArquivo(): void
    {
        $this->senhaAtual = '';
        $this->resetErrorBag();
        $this->janela = 'arquivo';
    }

    public function fechar(): void
    {
        $this->janela = null;
        $this->senhaAtual = '';
        $this->resetErrorBag();
    }

    /**
     * Grava os pesos editados como uma nova versão da metodologia (a anterior continua no histórico).
     */
    public function salvarPesos(): void
    {
        $this->validate([
            'pilares.*.*' => ['required', 'numeric', 'min:0', 'max:'.self::PESO_MAXIMO],
            'componentes.*.*.*' => ['required', 'numeric', 'min:0', 'max:'.self::PESO_MAXIMO],
        ], attributes: ['pilares.*.*' => 'peso do pilar', 'componentes.*.*.*' => 'peso do indicador']);

        $atual = Metodologia::sincronizar();
        $configuracao = $atual->configuracao;

        foreach (array_keys(self::INDICES) as $indice) {
            $total = 0.0;

            foreach ($configuracao[$indice]['pilares'] as $codigo => &$pilar) {
                $pilar['peso'] = $this->numero($this->pilares[$indice][$codigo] ?? $pilar['peso']);
                $total += $pilar['peso'];
                $somaDosComponentes = 0.0;

                foreach ($pilar['componentes'] as $indicador => &$componente) {
                    $componente['peso'] = $this->numero($this->componentes[$indice][$codigo][$indicador] ?? $componente['peso']);
                    $somaDosComponentes += $componente['peso'];
                }
                unset($componente);

                if ($pilar['peso'] > 0 && $somaDosComponentes <= 0) {
                    $this->addError("pilares.{$indice}.{$codigo}", "O pilar \"{$pilar['nome']}\" precisa de pelo menos um indicador com peso maior que zero (ou zere o peso do pilar).");

                    return;
                }
            }
            unset($pilar);

            if ($total <= 0) {
                $this->addError("pilares.{$indice}", 'Pelo menos um pilar do '.strtoupper($indice).' precisa ter peso maior que zero.');

                return;
            }
        }

        if (! $this->confirmarSenhaAtual()) {
            return;
        }

        $nova = Metodologia::ativarDoPainel($configuracao);

        if ($nova->is($atual)) {
            $this->fechar();
            session()->now('aviso', 'Os pesos continuam iguais aos da versão ativa: nada foi alterado.');

            return;
        }

        Auditoria::registrar('metodologia_pesos_alterados', ['versao' => $nova->versao]);

        $this->fechar();
        $this->carregarPesos();
        session()->now('sucesso', "Pesos salvos como versão {$nova->versao} da metodologia. Falta recalcular os índices para a mudança aparecer nos números.");
    }

    public function voltarAoArquivo(): void
    {
        if (! $this->confirmarSenhaAtual()) {
            return;
        }

        $versao = Metodologia::voltarAoArquivo();

        Auditoria::registrar('metodologia_arquivo_restaurado', ['versao' => $versao->versao]);

        $this->fechar();
        $this->carregarPesos();
        session()->now('sucesso', "Voltou à metodologia do arquivo de configuração (versão {$versao->versao}). Recalcule os índices para atualizar os números.");
    }

    public function recalcular(): void
    {
        $chave = 'admin-recalcular-indices';

        if (RateLimiter::tooManyAttempts($chave, 6)) {
            session()->now('aviso', 'O recálculo foi pedido várias vezes na última hora. Aguarde um pouco.');

            return;
        }

        $andamento = RecalcularIndices::andamento();

        if (in_array($andamento['estado'] ?? null, ['na_fila', 'calculando'], true) && now()->getTimestamp() - $andamento['em'] < 1800) {
            session()->now('aviso', 'Já existe um recálculo em andamento.');

            return;
        }

        RateLimiter::hit($chave, 3600);
        RecalcularIndices::registrarNaFila();
        RecalcularIndices::dispatch();

        Auditoria::registrar('indices_recalculados', ['versao' => Metodologia::sincronizar()->versao]);
        session()->now('sucesso', 'Recálculo pedido. Ele roda em segundo plano (precisa do processador de filas ligado); esta página acompanha o andamento.');
    }

    public function render(): View
    {
        $ativa = Metodologia::sincronizar();
        $andamento = RecalcularIndices::andamento();
        $emAndamento = in_array($andamento['estado'] ?? null, ['na_fila', 'calculando'], true);

        return view('livewire.admin.indices', [
            'ativa' => $ativa,
            'configuracao' => $ativa->configuracao,
            'versoes' => Metodologia::query()->orderByDesc('versao')->limit(10)->get(),
            'andamento' => $andamento,
            'emAndamento' => $emAndamento,
            'indices' => self::INDICES,
            'ultimoMes' => IndiceMunicipio::query()->max('competencia'),
            'municipiosCalculados' => IndiceMunicipio::query()->whereRaw('competencia = (select max(competencia) from indices_municipio)')->count(),
            'desatualizado' => (bool) cache()->get(VerificadorDeSaude::CHAVE_DE_INDICES_DESATUALIZADOS),
        ]);
    }

    private function carregarPesos(): void
    {
        $configuracao = Metodologia::sincronizar()->configuracao;
        $this->pilares = [];
        $this->componentes = [];

        foreach (array_keys(self::INDICES) as $indice) {
            foreach ($configuracao[$indice]['pilares'] as $codigo => $pilar) {
                $this->pilares[$indice][$codigo] = $pilar['peso'];

                foreach ($pilar['componentes'] as $indicador => $componente) {
                    $this->componentes[$indice][$codigo][$indicador] = $componente['peso'];
                }
            }
        }
    }

    private function numero(int|float|string $valor): int|float
    {
        $numero = $valor + 0;

        return $numero == (int) $numero ? (int) $numero : round((float) $numero, 2);
    }
}
