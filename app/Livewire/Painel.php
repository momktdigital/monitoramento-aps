<?php

namespace App\Livewire;

use App\Enums\TipoDeVisual;
use App\Livewire\Concerns\EscolheMunicipio;
use App\Models\Municipio;
use App\Models\PainelArea;
use App\Models\PainelWidget;
use App\Widgets\CatalogoDeVisuais;
use App\Widgets\PainelPadrao;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Painel do usuário, dividido em áreas (abas). Cada área reúne os visuais de um assunto; o usuário cria, renomeia,
 * reordena e remove áreas, escolhe qual abre primeiro e pode restaurar uma área ou o painel inteiro a qualquer momento.
 */
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

    /** Área aberta. Fica no endereço da página, então o link leva direto à aba (0 = a área inicial). */
    #[Url(as: 'area', except: 0)]
    public int $areaId = 0;

    public bool $formularioDeAreaAberto = false;

    /** Área sendo renomeada; nulo quando o formulário cria uma área nova. */
    public ?int $areaEmEdicao = null;

    public string $areaNome = '';

    /** Chave da área pronta que serve de ponto de partida para a nova área; vazio = área vazia. */
    public string $areaModelo = '';

    public function mount(PainelPadrao $padrao): void
    {
        $padrao->garantirPara(Auth::user());

        $this->iniciarMunicipio();
        $this->meses = array_key_exists((int) session('painel.meses'), self::JANELAS_EM_MESES) ? (int) session('painel.meses') : 24;
        $this->areaId = $this->areaDe($this->areas())->id;
    }

    public function updatedMeses(): void
    {
        if (! array_key_exists($this->meses, self::JANELAS_EM_MESES)) {
            $this->meses = 24;
        }

        session(['painel.meses' => $this->meses]);
    }

    // ------------------------------------------------------------------ Modo de edição e abas

    public function alternarEdicao(): void
    {
        $this->editando = ! $this->editando;
        $this->galeriaAberta = false;
        $this->formularioDeAreaAberto = false;
    }

    public function abrirArea(int $id): void
    {
        if ($this->areas()->contains('id', $id)) {
            $this->areaId = $id;
            $this->galeriaAberta = false;
            $this->formularioDeAreaAberto = false;
        }
    }

    /**
     * Setas do teclado na lista de abas: vai para a aba vizinha, voltando ao início (ou ao fim) nas pontas.
     */
    public function navegarArea(string $direcao): void
    {
        $ids = $this->areas()->pluck('id')->all();
        $indice = array_search($this->areaId, $ids, true);

        if ($indice === false || count($ids) < 2) {
            return;
        }

        $this->abrirArea($ids[($indice + ($direcao === 'anterior' ? -1 : 1) + count($ids)) % count($ids)]);
    }

    // ------------------------------------------------------------------ Áreas

    public function novaArea(): void
    {
        if ($this->areas()->count() >= (int) config('painel.maximo_de_areas')) {
            return;
        }

        $this->resetValidation();
        $this->areaEmEdicao = null;
        $this->areaNome = '';
        $this->areaModelo = '';
        $this->galeriaAberta = false;
        $this->formularioDeAreaAberto = true;
    }

    public function renomearArea(int $id): void
    {
        $area = $this->areas()->firstWhere('id', $id);

        if ($area === null) {
            return;
        }

        $this->resetValidation();
        $this->areaEmEdicao = $area->id;
        $this->areaNome = $area->nome;
        $this->areaModelo = '';
        $this->galeriaAberta = false;
        $this->formularioDeAreaAberto = true;
    }

    public function cancelarArea(): void
    {
        $this->formularioDeAreaAberto = false;
        $this->resetValidation();
    }

    public function salvarArea(PainelPadrao $padrao): void
    {
        $areas = $this->areas();
        $nome = trim(preg_replace('/\s+/', ' ', $this->areaNome) ?? '');

        $this->areaNome = $nome;

        $this->validate([
            'areaNome' => [
                'required',
                'string',
                'max:'.PainelArea::NOME_MAXIMO,
                fn (string $atributo, mixed $valor, Closure $falhar) => $areas->contains(fn (PainelArea $a): bool => $a->id !== $this->areaEmEdicao && mb_strtolower($a->nome) === mb_strtolower((string) $valor))
                    ? $falhar('Você já tem uma área com esse nome.')
                    : null,
            ],
        ], [
            'areaNome.required' => 'Dê um nome à área.',
            'areaNome.max' => 'Use até '.PainelArea::NOME_MAXIMO.' caracteres.',
        ]);

        if ($this->areaEmEdicao !== null) {
            $area = $areas->firstWhere('id', $this->areaEmEdicao);
            $area?->update(['nome' => $nome]);
        } else {
            if ($areas->count() >= (int) config('painel.maximo_de_areas')) {
                return;
            }

            $this->areaId = $padrao->criarArea(Auth::user(), $nome, $this->areaModelo)->id;
        }

        $this->formularioDeAreaAberto = false;
    }

    public function excluirArea(int $id): void
    {
        $areas = $this->areas();
        $area = $areas->firstWhere('id', $id);

        // O painel nunca fica sem área: a última não pode ser removida.
        if ($area === null || $areas->count() <= 1) {
            return;
        }

        DB::transaction(function () use ($area, $areas): void {
            $area->delete();

            if ($area->padrao) {
                $areas->firstWhere('id', '!=', $area->id)?->update(['padrao' => true]);
            }
        });

        if ($this->areaId === $id) {
            $this->areaId = $this->areaDe($this->areas())->id;
        }
    }

    public function definirComoInicial(int $id): void
    {
        if (! $this->areas()->contains('id', $id)) {
            return;
        }

        DB::transaction(function () use ($id): void {
            Auth::user()->areas()->update(['padrao' => false]);
            Auth::user()->areas()->whereKey($id)->update(['padrao' => true]);
        });
    }

    public function moverArea(int $id, string $direcao): void
    {
        $ids = $this->areas()->pluck('id')->all();
        $indice = array_search($id, $ids, true);

        if ($indice === false) {
            return;
        }

        $destino = $direcao === 'antes' ? $indice - 1 : ($direcao === 'depois' ? $indice + 1 : $indice);

        if ($destino < 0 || $destino >= count($ids) || $destino === $indice) {
            return;
        }

        [$ids[$indice], $ids[$destino]] = [$ids[$destino], $ids[$indice]];

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $posicao => $areaId) {
                PainelArea::whereKey($areaId)->where('user_id', Auth::id())->update(['posicao' => $posicao]);
            }
        });
    }

    public function restaurarArea(int $id, PainelPadrao $padrao): void
    {
        $area = $this->areas()->firstWhere('id', $id);

        if ($area !== null) {
            $padrao->restaurarArea($area);
        }
    }

    public function restaurarPadrao(PainelPadrao $padrao): void
    {
        $padrao->restaurar(Auth::user());

        $this->editando = false;
        $this->galeriaAberta = false;
        $this->formularioDeAreaAberto = false;
        $this->areaId = $this->areaDe($this->areas())->id;
    }

    // ------------------------------------------------------------------ Galeria de visuais

    public function abrirGaleria(): void
    {
        $this->galeriaAberta = true;
        $this->formularioDeAreaAberto = false;
        $this->busca = '';
    }

    public function fecharGaleria(): void
    {
        $this->galeriaAberta = false;
    }

    /**
     * Liga ou desliga um visual na área aberta: se já está lá, sai; se não está, entra no fim.
     */
    public function alternarVisual(string $tipo, string $codigo, CatalogoDeVisuais $catalogo): void
    {
        if (! $catalogo->permite($tipo, $codigo)) {
            return;
        }

        $area = $this->areaDe($this->areas());
        $existente = $area->widgets()->where('tipo', $tipo)->where('indicador', $codigo)->first();

        if ($existente !== null) {
            $existente->delete();

            return;
        }

        $this->criarVisual($area, TipoDeVisual::from($tipo), $codigo);
    }

    /**
     * Atalho da galeria: adiciona todos os visuais de um indicador à área aberta; se já estiverem todos, remove todos.
     */
    public function alternarIndicador(string $codigo, CatalogoDeVisuais $catalogo): void
    {
        $tipos = array_values(array_filter(TipoDeVisual::paraIndicador($codigo), fn (TipoDeVisual $tipo): bool => $catalogo->permite($tipo->value, $codigo)));

        if ($tipos === []) {
            return;
        }

        $area = $this->areaDe($this->areas());
        $presentes = $area->widgets()->where('indicador', $codigo)->pluck('tipo')->map(fn (TipoDeVisual $tipo): string => $tipo->value)->all();

        if (count(array_diff(array_map(fn (TipoDeVisual $t): string => $t->value, $tipos), $presentes)) === 0) {
            $area->widgets()->where('indicador', $codigo)->delete();

            return;
        }

        foreach ($tipos as $tipo) {
            if (! in_array($tipo->value, $presentes, true)) {
                $this->criarVisual($area, $tipo, $codigo);
            }
        }
    }

    private function criarVisual(PainelArea $area, TipoDeVisual $tipo, string $codigo): void
    {
        $area->widgets()->create([
            'user_id' => $area->user_id,
            'tipo' => $tipo,
            'indicador' => $codigo,
            'posicao' => $area->widgets()->exists() ? (int) $area->widgets()->max('posicao') + 1 : 0,
            'largura' => $tipo->larguraInicial(),
        ]);
    }

    // ------------------------------------------------------------------ Visuais da área

    public function remover(int $id): void
    {
        Auth::user()->widgets()->whereKey($id)->delete();
    }

    /**
     * Recebe a nova ordem completa dos visuais da área aberta (ids, na ordem em que ficaram na tela).
     *
     * @param  list<int|string>  $ids
     */
    public function reordenar(array $ids): void
    {
        $area = $this->areaDe($this->areas());
        $daArea = $area->widgets()->pluck('id')->all();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => in_array($id, $daArea, true))));
        $restantes = array_values(array_diff($daArea, $ids));

        DB::transaction(function () use ($ids, $restantes): void {
            foreach ([...$ids, ...$restantes] as $posicao => $id) {
                PainelWidget::whereKey($id)->where('user_id', Auth::id())->update(['posicao' => $posicao]);
            }
        });
    }

    public function mover(int $id, string $direcao): void
    {
        $ids = $this->areaDe($this->areas())->widgets()->pluck('id')->all();
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

    // ------------------------------------------------------------------ Tela

    public function render(CatalogoDeVisuais $catalogo, PainelPadrao $padrao): View
    {
        $areas = $this->areas();
        $area = $this->areaDe($areas);

        if ($this->areaId !== $area->id) {
            $this->areaId = $area->id;
        }

        $widgets = $area->widgets()->get();

        return view('livewire.painel', [
            'areas' => $areas,
            'area' => $area,
            'widgets' => $widgets,
            'municipios' => $this->municipiosAgrupados(),
            'municipio' => Municipio::find($this->municipioId),
            'janelas' => self::JANELAS_EM_MESES,
            'podeCriarArea' => $areas->count() < (int) config('painel.maximo_de_areas'),
            'maximoDeAreas' => (int) config('painel.maximo_de_areas'),
            'modelos' => $this->formularioDeAreaAberto && $this->areaEmEdicao === null ? $padrao->modelos() : [],
            'podeRestaurarArea' => $area->modelo !== null && $padrao->modelo($area->modelo) !== null,
            'grupos' => $this->galeriaAberta ? $catalogo->agrupados($this->busca) : [],
            'noPainel' => $this->galeriaAberta ? $widgets->map(fn (PainelWidget $w): string => $w->tipo->value.'|'.$w->indicador)->all() : [],
        ]);
    }

    /**
     * @return Collection<int, PainelArea>
     */
    private function areas(): Collection
    {
        return Auth::user()->areas()->withCount('widgets')->get();
    }

    /**
     * A área aberta: a escolhida, se for do usuário; senão a inicial; senão a primeira.
     *
     * @param  Collection<int, PainelArea>  $areas
     */
    private function areaDe(Collection $areas): PainelArea
    {
        return $areas->firstWhere('id', $this->areaId) ?? $areas->firstWhere('padrao', true) ?? $areas->first();
    }
}
