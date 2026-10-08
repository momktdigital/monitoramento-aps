<?php

namespace App\Livewire\Analises;

use App\Domain\Indicators\ConsultaDeIndicadores;
use App\Domain\Narrative\AnalistaDeComparacao;
use App\Domain\Scoring\ConsultaDeIndices;
use App\Enums\Polaridade;
use App\Livewire\Concerns\EscolheMunicipio;
use App\Models\Indicador;
use App\Models\IndiceMunicipio;
use App\Models\Municipio;
use App\Support\Competencia;
use App\Widgets\ConstrutorDaComparacao;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Compara até quatro municípios lado a lado: notas dos índices e último valor de cada indicador.
 * Os municípios escolhidos ficam no endereço da página, então a comparação pode ser compartilhada por link.
 */
#[Layout('layouts::app')]
#[Title('Comparar municípios')]
class Comparar extends Component
{
    use EscolheMunicipio;

    public const MAXIMO = 4;

    /** @var list<int> */
    #[Locked]
    #[Url(as: 'municipios')]
    public array $ids = [];

    /** Município ainda não incluído, escolhido no seletor "Adicionar município". */
    public int $novo = 0;

    /** @var array<int, int> município => posição da cor (0 a 3): cada município mantém a mesma cor enquanto estiver na comparação */
    #[Locked]
    public array $cores = [];

    public function mount(): void
    {
        $this->iniciarMunicipio();

        $validos = Municipio::ativo()->whereIn('id', array_map('intval', array_filter($this->ids, 'is_scalar')))->pluck('id')->all();
        $ids = array_values(array_unique(array_filter(array_map('intval', $this->ids), fn (int $id): bool => in_array($id, $validos, true))));

        $this->ids = array_slice($ids ?: $this->idsPadrao(), 0, self::MAXIMO);
        $this->cores = [];

        foreach ($this->ids as $posicao => $id) {
            $this->cores[$id] = $posicao;
        }
    }

    public function updatedNovo(): void
    {
        $this->adicionar($this->novo);
        $this->novo = 0;
    }

    public function adicionar(int $id): void
    {
        if (count($this->ids) >= self::MAXIMO || in_array($id, $this->ids, true) || ! Municipio::ativo()->whereKey($id)->exists()) {
            return;
        }

        $livres = array_diff(range(0, self::MAXIMO - 1), array_values($this->cores));
        $this->cores[$id] = (int) min($livres);
        $this->ids[] = $id;
    }

    public function remover(int $id): void
    {
        $this->ids = array_values(array_diff($this->ids, [$id]));
        unset($this->cores[$id]);
    }

    /**
     * Ponto de partida: o município em foco e os outros municípios piloto da mesma região de saúde.
     *
     * @return list<int>
     */
    private function idsPadrao(): array
    {
        $municipio = Municipio::find($this->municipioId);

        $vizinhos = Municipio::ativo()
            ->where('codigo_uf', $municipio->codigo_uf)
            ->where('regiao_saude_codigo', $municipio->regiao_saude_codigo)
            ->whereKeyNot($municipio->id)
            ->orderByDesc('piloto')
            ->orderBy('nome')
            ->limit(2)
            ->pluck('id')
            ->all();

        return [$municipio->id, ...$vizinhos];
    }

    public function render(ConsultaDeIndices $indices, ConsultaDeIndicadores $consulta, AnalistaDeComparacao $analista, ConstrutorDaComparacao $construtor): View
    {
        $escolhidos = Municipio::ativo()->whereIn('id', $this->ids)->get(['id', 'nome', 'regiao_saude_nome', 'codigo_uf', 'piloto'])->keyBy('id');
        $escolhidos = collect($this->ids)->filter(fn (int $id): bool => $escolhidos->has($id))->mapWithKeys(fn (int $id): array => [$id => $escolhidos[$id]]);
        $nomes = $escolhidos->map(fn (Municipio $m): string => $m->nome)->all();

        $codigoUf = $escolhidos->first()?->codigo_uf ?? Municipio::find($this->municipioId)->codigo_uf;
        $competencias = $indices->competencias($codigoUf);
        $competencia = $competencias[0] ?? null;
        $linhas = $competencia === null ? collect() : $indices->doMes($codigoUf, $competencia);
        $doMes = array_intersect_key($linhas->all(), array_flip($escolhidos->keys()->all()));

        $catalogo = Indicador::query()->ativo()->visivel()->where('codigo', 'not like', 'indice\_%')->orderBy('ordem')->get();
        $valores = $consulta->ultimosValores($escolhidos->keys()->all(), $catalogo->pluck('id')->all());
        $comDado = $catalogo->filter(fn (Indicador $indicador): bool => $escolhidos->keys()->contains(fn (int $id): bool => isset($valores[$id][$indicador->id])))->values();

        return view('livewire.analises.comparar', [
            'escolhidos' => $escolhidos,
            'cores' => $this->cores,
            'disponiveis' => $this->municipiosAgrupados()->map(fn (Collection $grupo) => $grupo->reject(fn (Municipio $m): bool => in_array($m->id, $this->ids, true))->values())->filter(fn (Collection $grupo): bool => $grupo->isNotEmpty()),
            'podeAdicionar' => count($this->ids) < self::MAXIMO,
            'maximo' => self::MAXIMO,
            'competenciaRotulo' => $competencia === null ? null : Competencia::rotulo($competencia),
            'indices' => $doMes,
            'notas' => ConstrutorDaComparacao::NOTAS,
            'grafico' => $construtor->construir($nomes, $this->cores, $doMes),
            'temIndices' => collect($doMes)->contains(fn (IndiceMunicipio $linha): bool => $linha->ina !== null || $linha->idaps !== null),
            'grupos' => $comDado->groupBy(fn (Indicador $i): string => $i->dimensao->rotulo()),
            'valores' => $valores,
            'melhores' => $this->melhores($comDado, $valores, $escolhidos->keys()->all()),
            'analise' => $analista->analisar($nomes, $doMes, $valores, $comDado->all(), $competencia),
        ]);
    }

    /**
     * Município com a melhor situação em cada indicador que tem sentido bom ou ruim (sem empate no topo).
     *
     * @param  Collection<int, Indicador>  $indicadores
     * @param  array<int, array<int, array{competencia: int, valor: float}>>  $valores
     * @param  list<int>  $ids
     * @return array<int, int> indicador => município
     */
    private function melhores(Collection $indicadores, array $valores, array $ids): array
    {
        $melhores = [];

        foreach ($indicadores as $indicador) {
            if ($indicador->polaridade === Polaridade::Neutra) {
                continue;
            }

            $avaliados = [];

            foreach ($ids as $id) {
                if (isset($valores[$id][$indicador->id])) {
                    $avaliados[$id] = $indicador->valorAvaliado($valores[$id][$indicador->id]['valor']);
                }
            }

            if (count($avaliados) < 2) {
                continue;
            }

            $alvo = $indicador->polaridade === Polaridade::MaiorMelhor ? max($avaliados) : min($avaliados);
            $lideres = array_keys(array_filter($avaliados, fn (float $v): bool => $v == $alvo));

            if (count($lideres) === 1) {
                $melhores[$indicador->id] = $lideres[0];
            }
        }

        return $melhores;
    }
}
