<?php

namespace App\Livewire\Analises;

use App\Domain\Indicators\ConsultaDeIndicadores;
use App\Domain\Narrative\AnalistaDoMapa;
use App\Livewire\Concerns\EscolheMunicipio;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Widgets\ConstrutorDoMapa;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mapa dos municípios do estado, coloridos pelo valor de um indicador ou índice.
 */
#[Layout('layouts::app')]
#[Title('Mapa')]
class Mapa extends Component
{
    use EscolheMunicipio;

    private const INDICADOR_PADRAO = 'indice_idaps';

    #[Url(as: 'indicador')]
    public string $indicador = '';

    public function mount(): void
    {
        $this->iniciarMunicipio();
    }

    /**
     * Indicadores ativos e visíveis que já têm algum dado, agrupados por dimensão.
     *
     * @return Collection<int, Indicador>
     */
    private function opcoes(): Collection
    {
        return Indicador::query()
            ->ativo()
            ->visivel()
            ->whereExists(fn ($consulta) => $consulta->selectRaw('1')->from('valores_indicador')->whereColumn('valores_indicador.indicador_id', 'indicadores.id'))
            ->orderBy('ordem')
            ->get();
    }

    public function render(ConsultaDeIndicadores $consulta, ConstrutorDoMapa $construtor, AnalistaDoMapa $analista): View
    {
        $municipio = Municipio::find($this->municipioId);
        $opcoes = $this->opcoes();
        $indicador = $opcoes->firstWhere('codigo', $this->indicador) ?? $opcoes->firstWhere('codigo', self::INDICADOR_PADRAO) ?? $opcoes->first();

        $dados = [
            'municipio' => $municipio,
            'municipios' => $this->municipiosAgrupados(),
            'opcoes' => $opcoes->groupBy(fn (Indicador $i): string => $i->dimensao->rotulo()),
            'indicadorAtual' => $indicador,
        ];

        if ($indicador === null) {
            return view('livewire.analises.mapa', $dados + ['semDados' => true]);
        }

        $this->indicador = $indicador->codigo;
        $competencia = $consulta->competenciaDoMapa($indicador, $municipio->codigo_uf);

        if ($competencia === null) {
            return view('livewire.analises.mapa', $dados + ['semDados' => true]);
        }

        $valores = $consulta->valoresDaCompetencia($indicador, $competencia, $municipio->codigo_uf);
        $municipiosDoEstado = Municipio::query()->ativo()->where('codigo_uf', $municipio->codigo_uf)->get(['id', 'nome', 'regiao_saude_nome'])->keyBy('id');
        $rotulo = $indicador->rotuloDaCompetencia($competencia);

        return view('livewire.analises.mapa', $dados + [
            'semDados' => false,
            'competenciaRotulo' => $rotulo,
            'grafico' => $construtor->construir($indicador, $rotulo, $valores, $municipiosDoEstado, $municipio->id, $municipio->codigo_uf),
            'tabela' => $construtor->tabela($indicador, $valores, $municipiosDoEstado),
            'analise' => $analista->analisar($indicador, $rotulo, $valores, $municipiosDoEstado->pluck('nome', 'id')->all(), $municipio->id, $municipiosDoEstado->count()),
            'secoes' => [
                'O que é' => $indicador->o_que_e,
                'Como é calculado' => $indicador->como_calcula,
                'Para que serve' => $indicador->para_que_serve,
                'Como interpretar' => $indicador->como_interpretar,
            ],
        ]);
    }
}
