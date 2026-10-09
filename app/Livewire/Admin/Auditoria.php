<?php

namespace App\Livewire\Admin;

use App\Administracao\ConsultaDeAuditoria;
use App\Administracao\RotulosDeAuditoria;
use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::app')]
#[Title('Auditoria — Administração')]
class Auditoria extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $busca = '';

    #[Url(except: '')]
    public string $grupo = '';

    #[Url(except: '')]
    public string $evento = '';

    #[Url(except: '')]
    public string $de = '';

    #[Url(except: '')]
    public string $ate = '';

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
        if (in_array($campo, ['busca', 'grupo', 'evento', 'de', 'ate'], true)) {
            $this->resetPage();
        }
    }

    public function updatedGrupo(): void
    {
        $this->evento = '';
    }

    public function limparFiltros(): void
    {
        $this->reset(['busca', 'grupo', 'evento', 'de', 'ate']);
        $this->resetPage();
    }

    public function render(): View
    {
        $filtros = $this->filtros();
        $eventos = $this->grupo !== '' ? RotulosDeAuditoria::eventosDoGrupo($this->grupo) : array_keys(RotulosDeAuditoria::todos());

        return view('livewire.admin.auditoria', [
            'registros' => ConsultaDeAuditoria::aplicar($filtros)->paginate(25),
            'filtros' => array_filter($filtros, fn ($valor) => $valor !== ''),
            'grupos' => RotulosDeAuditoria::GRUPOS,
            'eventos' => collect($eventos)->mapWithKeys(fn (string $codigo) => [$codigo => RotulosDeAuditoria::rotulo($codigo)])->sort(),
            'total' => AuditLog::count(),
            'maisAntigo' => AuditLog::min('created_at'),
            'retencaoEmDias' => max(30, (int) config('aps.auditoria.retencao_dias')),
        ]);
    }

    /**
     * @return array{q: string, grupo: string, evento: string, de: string, ate: string}
     */
    private function filtros(): array
    {
        return ['q' => $this->busca, 'grupo' => $this->grupo, 'evento' => $this->evento, 'de' => $this->de, 'ate' => $this->ate];
    }
}
