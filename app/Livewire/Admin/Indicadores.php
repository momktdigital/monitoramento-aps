<?php

namespace App\Livewire\Admin;

use App\Administracao\VerificadorDeSaude;
use App\Enums\Dimensao;
use App\Models\Indicador;
use App\Models\Metodologia;
use App\Models\ValorIndicador;
use App\Support\Auditoria;
use App\Support\VersaoDosDados;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('Indicadores — Administração')]
class Indicadores extends Component
{
    private const COLUNAS_DE_TEXTO = ['o_que_e', 'como_calcula', 'para_que_serve', 'como_interpretar'];

    #[Url(as: 'q', except: '')]
    public string $busca = '';

    #[Url(except: '')]
    public string $dimensao = '';

    #[Locked]
    public ?int $editandoId = null;

    public string $nome = '';

    public string $oQueE = '';

    public string $comoCalcula = '';

    public string $paraQueServe = '';

    public string $comoInterpretar = '';

    public function mount(): void
    {
        Gate::authorize('administrar');
    }

    public function hydrate(): void
    {
        Gate::authorize('administrar');
    }

    /**
     * Indicador inativo não é mais coletado nem entra nos índices; some do painel e das análises.
     */
    public function alternarAtivo(int $id): void
    {
        $indicador = Indicador::findOrFail($id);
        $indicador->update(['ativo' => ! $indicador->ativo]);

        Auditoria::registrar('indicador_ativo_alterado', ['codigo' => $indicador->codigo, 'ativo' => $indicador->ativo]);

        VerificadorDeSaude::marcarIndicesDesatualizados();
        VersaoDosDados::renovar();

        if ($this->usadosNosIndices()->has($indicador->codigo)) {
            session()->now('aviso', "{$indicador->nome} faz parte do cálculo dos índices. A mudança só vale depois de Índices › Recalcular agora.");
        }
    }

    /**
     * Indicador oculto continua sendo coletado e calculado, mas não aparece nas listas e galerias dos usuários.
     */
    public function alternarVisivel(int $id): void
    {
        $indicador = Indicador::findOrFail($id);
        $indicador->update(['visivel' => ! $indicador->visivel]);

        Auditoria::registrar('indicador_visivel_alterado', ['codigo' => $indicador->codigo, 'visivel' => $indicador->visivel]);

        VersaoDosDados::renovar();
    }

    public function editar(int $id): void
    {
        $indicador = Indicador::findOrFail($id);

        $this->editandoId = $indicador->id;
        $this->nome = $indicador->nome;
        $this->oQueE = (string) $indicador->o_que_e;
        $this->comoCalcula = (string) $indicador->como_calcula;
        $this->paraQueServe = (string) $indicador->para_que_serve;
        $this->comoInterpretar = (string) $indicador->como_interpretar;
        $this->resetErrorBag();
    }

    public function fechar(): void
    {
        $this->editandoId = null;
        $this->resetErrorBag();
    }

    public function salvar(): void
    {
        $indicador = Indicador::findOrFail($this->editandoId);

        $dados = $this->validate([
            'nome' => ['required', 'string', 'max:120'],
            'oQueE' => ['required', 'string', 'max:1500'],
            'comoCalcula' => ['required', 'string', 'max:1500'],
            'paraQueServe' => ['required', 'string', 'max:1500'],
            'comoInterpretar' => ['required', 'string', 'max:1500'],
        ], attributes: ['nome' => 'nome', 'oQueE' => '"O que é"', 'comoCalcula' => '"Como é calculado"', 'paraQueServe' => '"Para que serve"', 'comoInterpretar' => '"Como interpretar"']);

        $indicador->update([
            'nome' => trim($dados['nome']),
            'o_que_e' => trim($dados['oQueE']),
            'como_calcula' => trim($dados['comoCalcula']),
            'para_que_serve' => trim($dados['paraQueServe']),
            'como_interpretar' => trim($dados['comoInterpretar']),
        ]);

        $campos = array_values(array_diff(array_keys($indicador->getChanges()), ['updated_at']));

        Auditoria::registrar('indicador_atualizado', ['codigo' => $indicador->codigo, 'alteracoes' => array_fill_keys($campos, true)]);

        VersaoDosDados::renovar();
        $this->fechar();
        session()->now('sucesso', "Indicador {$indicador->nome} atualizado. O \"?\" dos visuais e a página de Metodologia já mostram os novos textos.");
    }

    /**
     * Volta os textos de ajuda e o nome para o que vem do catálogo do sistema.
     */
    public function restaurarTextos(): void
    {
        $indicador = Indicador::findOrFail($this->editandoId);
        $original = collect(require database_path('seeders/data/indicadores.php'))->firstWhere('codigo', $indicador->codigo);

        if ($original === null) {
            $this->addError('nome', 'Este indicador não existe no catálogo original; não há o que restaurar.');

            return;
        }

        $indicador->update(['nome' => $original['nome']] + array_intersect_key($original, array_flip([...self::COLUNAS_DE_TEXTO, 'texto_versao'])));

        Auditoria::registrar('indicador_textos_restaurados', ['codigo' => $indicador->codigo]);

        VersaoDosDados::renovar();
        $this->editar($indicador->id);
        session()->now('sucesso', 'Textos restaurados para o padrão do catálogo.');
    }

    public function render(): View
    {
        $indicadores = Indicador::query()
            ->when($this->busca !== '', function ($consulta): void {
                $termo = '%'.addcslashes(trim($this->busca), '%_\\').'%';
                $consulta->where(fn ($interna) => $interna->where('nome', 'like', $termo)->orWhere('codigo', 'like', $termo));
            })
            ->when($this->dimensao !== '', fn ($consulta) => $consulta->where('dimensao', $this->dimensao))
            ->orderBy('ordem')->get();

        $cobertura = ValorIndicador::query()
            ->selectRaw('indicador_id, count(distinct municipio_id) as municipios, max(competencia) as ultima')
            ->whereIn('indicador_id', $indicadores->modelKeys())
            ->groupBy('indicador_id')->get()->keyBy('indicador_id');

        return view('livewire.admin.indicadores', [
            'grupos' => $indicadores->groupBy(fn (Indicador $indicador) => $indicador->dimensao->rotulo()),
            'cobertura' => $cobertura,
            'usadosNosIndices' => $this->usadosNosIndices(),
            'dimensoes' => Dimensao::cases(),
            'editando' => $this->editandoId !== null ? Indicador::find($this->editandoId) : null,
            'totais' => ['todos' => Indicador::count(), 'ativos' => Indicador::where('ativo', true)->count(), 'ocultos' => Indicador::where('visivel', false)->count()],
        ]);
    }

    /**
     * Quais indicadores entram no cálculo (e em qual índice), segundo a metodologia ativa.
     *
     * @return Collection<string, list<string>>
     */
    private function usadosNosIndices()
    {
        $configuracao = Metodologia::query()->ativa()->value('configuracao') ?? (array) config('indices');
        $usados = [];

        foreach (['ina' => 'INA', 'idaps' => 'IDAPS'] as $indice => $rotulo) {
            foreach ($configuracao[$indice]['pilares'] ?? [] as $pilar) {
                foreach (array_keys($pilar['componentes'] ?? []) as $codigo) {
                    $usados[$codigo][] = $rotulo;
                }
            }
        }

        return collect($usados);
    }
}
