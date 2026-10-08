<?php

namespace App\Livewire\Analises;

use App\Domain\Narrative\AnalistaDaMatriz;
use App\Domain\Scoring\ConsultaDeIndices;
use App\Enums\QuadranteIpf;
use App\Livewire\Concerns\EscolheMunicipio;
use App\Models\IndiceMunicipio;
use App\Models\Municipio;
use App\Support\Competencia;
use App\Widgets\ConstrutorDaMatriz;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Matriz INA × IDAPS: cada município é um ponto, e o quadrante diz onde o apoio tem mais potencial.
 */
#[Layout('layouts::app')]
#[Title('Matriz de prioridade')]
class Matriz extends Component
{
    use EscolheMunicipio;

    /** Mês de referência (AAAAMM); 0 = o mais recente com índices. */
    public int $competencia = 0;

    /** Filtro do ranking: valor do enum `QuadranteIpf`; 0 = todos. */
    public int $quadrante = 0;

    /** Filtro do ranking: nome da região de saúde; vazio = todas. */
    public string $regiao = '';

    public function mount(): void
    {
        $this->iniciarMunicipio();
    }

    public function updatedQuadrante(): void
    {
        if ($this->quadrante !== 0 && QuadranteIpf::tryFrom($this->quadrante) === null) {
            $this->quadrante = 0;
        }
    }

    public function render(ConsultaDeIndices $consulta, ConstrutorDaMatriz $construtor, AnalistaDaMatriz $analista): View
    {
        $municipio = Municipio::find($this->municipioId);
        $competencias = $consulta->competencias($municipio->codigo_uf);

        if ($competencias === []) {
            return view('livewire.analises.matriz', ['municipio' => $municipio, 'municipios' => $this->municipiosAgrupados(), 'semIndices' => true]);
        }

        $competencia = in_array($this->competencia, $competencias, true) ? $this->competencia : $competencias[0];
        $linhas = $consulta->doMes($municipio->codigo_uf, $competencia);
        $cortes = $consulta->cortes($linhas);
        $resumo = $consulta->resumoDosQuadrantes($linhas);
        $porPrioridade = $consulta->porPrioridade($linhas);
        $selecionada = $linhas->get($municipio->id);

        $posicao = $porPrioridade->search(fn (IndiceMunicipio $linha): bool => $linha->municipio_id === $municipio->id);
        $anterior = $consulta->serie($municipio->id)->first(fn (IndiceMunicipio $linha): bool => $linha->competencia < $competencia && $linha->competencia >= $competencia - 100);

        $ranking = $porPrioridade
            ->filter(fn (IndiceMunicipio $linha): bool => ($this->quadrante === 0 || $linha->ipf_quadrante->value === $this->quadrante)
                && ($this->regiao === '' || $linha->municipio->regiao_saude_nome === $this->regiao))
            ->values();

        return view('livewire.analises.matriz', [
            'semIndices' => false,
            'municipio' => $municipio,
            'municipios' => $this->municipiosAgrupados(),
            'competencias' => collect($competencias)->mapWithKeys(fn (int $c): array => [$c => Competencia::rotulo($c)])->all(),
            'competenciaAtual' => $competencia,
            'competenciaRotulo' => Competencia::rotulo($competencia),
            'selecionada' => $selecionada,
            'grafico' => $construtor->construir($linhas, $cortes, $municipio->id),
            'cortes' => $cortes,
            'resumo' => $resumo,
            'quadrantes' => QuadranteIpf::cases(),
            'ranking' => $ranking,
            'posicoes' => $porPrioridade->values()->mapWithKeys(fn (IndiceMunicipio $linha, int $indice): array => [$linha->municipio_id => $indice + 1])->all(),
            'rankingDeDesempenho' => $linhas->filter(fn (IndiceMunicipio $linha): bool => $linha->idaps !== null)->sortByDesc('idaps')->values(),
            'posicaoNoRanking' => $posicao === false ? null : $posicao + 1,
            'regioes' => $linhas->pluck('municipio.regiao_saude_nome')->filter()->unique()->sort()->values()->all(),
            'totalDeMunicipios' => $linhas->count(),
            'naMatriz' => $porPrioridade->count(),
            'analise' => $analista->analisar(
                $municipio->nome,
                $selecionada,
                $anterior,
                $resumo,
                $posicao === false ? null : ['posicao' => $posicao + 1, 'total' => $porPrioridade->count()],
                $competencia,
            ),
        ]);
    }
}
