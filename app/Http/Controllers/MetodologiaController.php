<?php

namespace App\Http\Controllers;

use App\Enums\QuadranteIpf;
use App\Integrations\RegistroDeConectores;
use App\Models\Indicador;
use App\Models\Integracao;
use App\Models\Metodologia;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Página pública de Metodologia e Fontes: como INA, IDAPS e IPF são calculados (com os pesos da versão em uso),
 * quais indicadores existem e de onde vêm os dados. Só informações gerais: nada de usuários, chaves ou erros internos.
 */
class MetodologiaController extends Controller
{
    public function __invoke(): View
    {
        $metodologia = Metodologia::ativa()->first();
        $configuracao = $metodologia?->configuracao ?? (array) config('indices');

        $codigos = [];

        foreach (['ina', 'idaps'] as $indice) {
            foreach ($configuracao[$indice]['pilares'] as $pilar) {
                array_push($codigos, ...array_keys($pilar['componentes']));
            }
        }

        $indicadoresDaMetodologia = Indicador::query()->whereIn('codigo', $codigos)->withExists('valores as com_dados')->get()->keyBy('codigo');
        $ultimasCargas = Integracao::query()->pluck('ultimo_sucesso_em', 'fonte');

        $conteudo = view('metodologia.conteudo', [
            'versao' => $metodologia?->versao,
            'configuracao' => $configuracao,
            'indices' => [
                'ina' => $this->indice('ina', $configuracao, $indicadoresDaMetodologia),
                'idaps' => $this->indice('idaps', $configuracao, $indicadoresDaMetodologia),
            ],
            'quadrantes' => QuadranteIpf::cases(),
            'catalogo' => Indicador::query()->visivel()->orderBy('ordem')->get()->groupBy(fn (Indicador $indicador): string => $indicador->dimensao->rotulo()),
            'fontes' => collect(RegistroDeConectores::todos())->map(fn ($conector, string $fonte): array => [
                'nome' => $conector->nome(),
                'descricao' => $conector->descricao(),
                'atualizada_em' => $ultimasCargas[$fonte] ?? null,
            ])->values()->all(),
        ])->render();

        $layout = auth()->check() ? 'layouts.app' : 'components.layouts.publico';

        return view($layout, ['slot' => new HtmlString($conteudo), 'title' => 'Metodologia e fontes']);
    }

    /**
     * Pilares de um índice com o peso de cada um (em % do total) e de cada indicador dentro do pilar.
     *
     * @param  array<string, mixed>  $configuracao
     * @param  Collection<string, Indicador>  $indicadores
     * @return array{nome: string, pilares: list<array<string, mixed>>}
     */
    private function indice(string $chave, array $configuracao, $indicadores): array
    {
        $pilares = $configuracao[$chave]['pilares'];
        $somaDosPilares = array_sum(array_column($pilares, 'peso'));
        $resultado = [];

        foreach ($pilares as $pilar) {
            $somaDosComponentes = array_sum(array_column($pilar['componentes'], 'peso'));

            $resultado[] = [
                'nome' => $pilar['nome'],
                'peso' => round($pilar['peso'] / $somaDosPilares * 100),
                'obrigatorio' => (bool) ($pilar['obrigatorio'] ?? false),
                'componentes' => collect($pilar['componentes'])->map(function (array $componente, string $codigo) use ($indicadores, $somaDosComponentes): array {
                    $indicador = $indicadores[$codigo] ?? null;

                    return [
                        'nome' => $indicador?->nome ?? $codigo,
                        'peso' => round($componente['peso'] / $somaDosComponentes * 100),
                        'sentido' => $componente['sentido'],
                        'em_uso' => $indicador !== null && $indicador->ativo && (bool) $indicador->com_dados,
                    ];
                })->values()->all(),
            ];
        }

        return ['nome' => $configuracao[$chave]['nome'], 'pilares' => $resultado];
    }
}
