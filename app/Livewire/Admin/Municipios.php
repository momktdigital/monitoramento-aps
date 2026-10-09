<?php

namespace App\Livewire\Admin;

use App\Administracao\VerificadorDeSaude;
use App\Jobs\SincronizarMunicipiosJob;
use App\Models\Municipio;
use App\Support\Auditoria;
use App\Support\VersaoDosDados;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::app')]
#[Title('Municípios — Administração')]
class Municipios extends Component
{
    use WithPagination;

    private const SINCRONIZACOES_POR_HORA = 3;

    #[Url(as: 'q', except: '')]
    public string $busca = '';

    #[Url(except: '')]
    public string $situacao = '';

    #[Url(except: '')]
    public string $regiao = '';

    public function mount(): void
    {
        Gate::authorize('administrar');
    }

    public function hydrate(): void
    {
        Gate::authorize('administrar');
    }

    public function updating(string $campo): void
    {
        if (in_array($campo, ['busca', 'situacao', 'regiao'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Município inativo sai da comparação e dos índices (e some das listas de escolha).
     */
    public function alternarAtivo(int $id): void
    {
        $municipio = Municipio::findOrFail($id);
        $municipio->update(['ativo' => ! $municipio->ativo]);

        Auditoria::registrar('municipio_ativo_alterado', ['municipio_id' => $municipio->id, 'nome' => $municipio->nome, 'ativo' => $municipio->ativo]);

        $this->aposAlteracaoQueMudaOsIndices();
    }

    /**
     * Piloto é só uma marcação de acompanhamento (filtros e destaques); não muda os índices.
     */
    public function alternarPiloto(int $id): void
    {
        $municipio = Municipio::findOrFail($id);
        $municipio->update(['piloto' => ! $municipio->piloto]);

        Auditoria::registrar('municipio_piloto_alterado', ['municipio_id' => $municipio->id, 'nome' => $municipio->nome, 'piloto' => $municipio->piloto]);

        VersaoDosDados::renovar();
    }

    public function definirPilotoDaRegiao(bool $piloto): void
    {
        if ($this->regiao === '') {
            return;
        }

        $quantidade = Municipio::query()->where('regiao_saude_nome', $this->regiao)->update(['piloto' => $piloto]);

        Auditoria::registrar('municipios_piloto_em_lote', ['nome' => $this->regiao, 'piloto' => $piloto, 'quantidade' => $quantidade]);

        VersaoDosDados::renovar();
        session()->now('sucesso', $piloto
            ? "{$quantidade} município(s) da região {$this->regiao} marcados como piloto."
            : "Piloto removido de {$quantidade} município(s) da região {$this->regiao}.");
    }

    public function sincronizar(): void
    {
        $chave = 'admin-sincronizar-municipios';

        if (RateLimiter::tooManyAttempts($chave, self::SINCRONIZACOES_POR_HORA)) {
            session()->now('aviso', 'A sincronização já foi pedida várias vezes na última hora. Tente de novo mais tarde.');

            return;
        }

        RateLimiter::hit($chave, 3600);
        SincronizarMunicipiosJob::dispatch();

        Auditoria::registrar('municipios_sincronizacao_pedida');
        session()->now('sucesso', 'Sincronização pedida. Ela roda em segundo plano (precisa do processador de filas ligado) e não altera as marcações de ativo e piloto.');
    }

    public function render(): View
    {
        $consulta = Municipio::query()
            ->when($this->busca !== '', fn ($q) => $q->buscarPorNome($this->busca))
            ->when($this->regiao !== '', fn ($q) => $q->where('regiao_saude_nome', $this->regiao))
            ->when($this->situacao === 'ativos', fn ($q) => $q->where('ativo', true))
            ->when($this->situacao === 'inativos', fn ($q) => $q->where('ativo', false))
            ->when($this->situacao === 'piloto', fn ($q) => $q->where('piloto', true));

        return view('livewire.admin.municipios', [
            'municipios' => $consulta->orderBy('uf')->orderBy('nome')->paginate(20),
            'regioes' => Municipio::query()->whereNotNull('regiao_saude_nome')->distinct()->orderBy('regiao_saude_nome')->pluck('regiao_saude_nome'),
            'totais' => [
                'todos' => Municipio::count(),
                'ativos' => Municipio::where('ativo', true)->count(),
                'piloto' => Municipio::where('piloto', true)->count(),
                'ufs' => Municipio::query()->distinct()->count('uf'),
            ],
            'regiaoEscolhida' => $this->regiao !== '' ? Municipio::where('regiao_saude_nome', $this->regiao)->count() : 0,
        ]);
    }

    private function aposAlteracaoQueMudaOsIndices(): void
    {
        VerificadorDeSaude::marcarIndicesDesatualizados();
        VersaoDosDados::renovar();

        session()->now('aviso', 'A mudança só entra nos índices no próximo cálculo. Use Índices › Recalcular agora para atualizar já.');
    }
}
