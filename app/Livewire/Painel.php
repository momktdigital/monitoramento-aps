<?php

namespace App\Livewire;

use App\Enums\TipoDeVisual;
use App\Livewire\Concerns\EscolheMunicipio;
use App\Models\Municipio;
use App\Models\PainelWidget;
use App\Widgets\CatalogoDeVisuais;
use App\Widgets\PainelPadrao;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('Meu painel')]
class Painel extends Component
{
    use EscolheMunicipio;

    public const JANELAS_EM_MESES = [12 => 'Últimos 12 meses', 24 => 'Últimos 24 meses', 36 => 'Últimos 36 meses'];

    public int $meses = 24;

    public bool $editando = false;

    public bool $galeriaAberta = false;

    public string $busca = '';

    public function mount(PainelPadrao $padrao): void
    {
        $usuario = Auth::user();

        $padrao->garantirPara($usuario);

        $this->iniciarMunicipio();
        $this->meses = array_key_exists((int) session('painel.meses'), self::JANELAS_EM_MESES) ? (int) session('painel.meses') : 24;
    }

    public function updatedMeses(): void
    {
        if (! array_key_exists($this->meses, self::JANELAS_EM_MESES)) {
            $this->meses = 24;
        }

        session(['painel.meses' => $this->meses]);
    }

    public function alternarEdicao(): void
    {
        $this->editando = ! $this->editando;
        $this->galeriaAberta = false;
    }

    public function abrirGaleria(): void
    {
        $this->galeriaAberta = true;
        $this->busca = '';
    }

    public function fecharGaleria(): void
    {
        $this->galeriaAberta = false;
    }

    public function adicionar(string $tipo, string $codigo, CatalogoDeVisuais $catalogo): void
    {
        if (! $catalogo->permite($tipo, $codigo)) {
            return;
        }

        $visual = TipoDeVisual::from($tipo);
        $usuario = Auth::user();

        if ($usuario->widgets()->where('tipo', $visual->value)->where('indicador', $codigo)->exists()) {
            return;
        }

        $usuario->widgets()->create([
            'tipo' => $visual,
            'indicador' => $codigo,
            'posicao' => (int) $usuario->widgets()->max('posicao') + 1,
            'largura' => $visual->larguraInicial(),
        ]);
    }

    public function remover(int $id): void
    {
        Auth::user()->widgets()->whereKey($id)->delete();
    }

    /**
     * Recebe a nova ordem completa (ids dos visuais do usuário, na ordem em que ficaram na tela).
     *
     * @param  list<int|string>  $ids
     */
    public function reordenar(array $ids): void
    {
        $doUsuario = Auth::user()->widgets()->pluck('id')->all();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => in_array($id, $doUsuario, true))));
        $restantes = array_values(array_diff($doUsuario, $ids));

        DB::transaction(function () use ($ids, $restantes): void {
            foreach ([...$ids, ...$restantes] as $posicao => $id) {
                PainelWidget::whereKey($id)->where('user_id', Auth::id())->update(['posicao' => $posicao]);
            }
        });
    }

    public function mover(int $id, string $direcao): void
    {
        $ids = Auth::user()->widgets()->pluck('id')->all();
        $indice = array_search($id, $ids, true);

        if ($indice === false) {
            return;
        }

        $destino = $direcao === 'antes' ? $indice - 1 : ($direcao === 'depois' ? $indice + 1 : $indice);

        if ($destino < 0 || $destino >= count($ids) || $destino === $indice) {
            return;
        }

        [$ids[$indice], $ids[$destino]] = [$ids[$destino], $ids[$indice]];

        $this->reordenar($ids);
    }

    public function alterarLargura(int $id, int $largura): void
    {
        if ($largura < PainelWidget::LARGURA_MINIMA || $largura > PainelWidget::LARGURA_MAXIMA) {
            return;
        }

        Auth::user()->widgets()->whereKey($id)->update(['largura' => $largura]);
    }

    public function restaurarPadrao(PainelPadrao $padrao): void
    {
        $padrao->restaurar(Auth::user());
        $this->editando = false;
        $this->galeriaAberta = false;
    }

    public function render(CatalogoDeVisuais $catalogo): View
    {
        return view('livewire.painel', [
            'widgets' => Auth::user()->widgets()->get(),
            'municipios' => $this->municipiosAgrupados(),
            'municipio' => Municipio::find($this->municipioId),
            'janelas' => self::JANELAS_EM_MESES,
            'grupos' => $this->galeriaAberta ? $catalogo->agrupados($this->busca) : [],
            'tipos' => TipoDeVisual::cases(),
            'noPainel' => $this->galeriaAberta
                ? Auth::user()->widgets()->get(['tipo', 'indicador'])->map(fn (PainelWidget $w): string => $w->tipo->value.'|'.$w->indicador)->all()
                : [],
        ]);
    }
}
