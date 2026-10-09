<?php

namespace App\Livewire\Painel;

use App\Domain\Narrative\Analise;
use App\Enums\TipoDeVisual;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Widgets\ConstrutorDeVisual;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Um visual do painel (destaque, evolução, ranking, mapa ou matriz). Carrega sob demanda: a página abre na hora
 * e cada visual chega em paralelo, no seu próprio ritmo.
 */
#[Lazy(isolate: false)]
class Visual extends Component
{
    #[Locked]
    public int $widgetId;

    #[Reactive]
    public int $municipioId;

    #[Reactive]
    public int $meses = 24;

    /** Só o ranking usa: compara com a região de saúde ou com o estado. */
    public string $escopo = ConstrutorDeVisual::ESCOPO_REGIAO;

    public function placeholder(): View
    {
        return view('livewire.painel.visual-carregando');
    }

    public function definirEscopo(string $escopo): void
    {
        $this->escopo = $escopo === ConstrutorDeVisual::ESCOPO_ESTADO ? ConstrutorDeVisual::ESCOPO_ESTADO : ConstrutorDeVisual::ESCOPO_REGIAO;
    }

    public function render(ConstrutorDeVisual $construtor): View
    {
        $widget = Auth::user()->widgets()->find($this->widgetId);
        $municipio = Municipio::find($this->municipioId);
        $indicador = $widget === null ? null : Indicador::where('codigo', $widget->indicador)->first();

        if ($widget === null || $municipio === null || $indicador === null || ! $widget->tipo->aplicaA($indicador)) {
            return view('livewire.painel.visual-indisponivel');
        }

        $visual = $construtor->construir($widget->tipo, $indicador, $municipio, $this->meses, $widget->tipo === TipoDeVisual::Ranking ? $this->escopo : ConstrutorDeVisual::ESCOPO_REGIAO);

        return view('livewire.painel.visual', [
            'widget' => $widget,
            'visual' => $visual,
            'analise' => Analise::fromArray($visual['analise']),
        ]);
    }
}
