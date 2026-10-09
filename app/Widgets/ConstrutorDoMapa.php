<?php

namespace App\Widgets;

use App\Domain\Indicators\CalculadorDeBenchmarks;
use App\Enums\Polaridade;
use App\Models\Indicador;
use App\Models\Municipio;
use App\Support\Formatador;
use Illuminate\Support\Collection;

/**
 * Dados do mapa coroplético de um indicador: cada município recebe uma de até cinco classes de mesmo tamanho
 * (quintis), pintadas com uma única cor em tons do mais claro ao mais escuro. A cor é calculada aqui e a legenda
 * usa exatamente as mesmas faixas.
 */
class ConstrutorDoMapa
{
    /** Passos 100, 200, 300, 450 e 600 da rampa sequencial azul da paleta de visualização. */
    private const RAMPA = ['#cde2fb', '#9ec5f4', '#6da7ec', '#2a78d6', '#184f95'];

    private const SEM_DADO = '#e8e6df';

    /**
     * @param  array<int, float>  $valores  município => valor na competência exibida
     * @param  Collection<int, Municipio>  $municipios  todos os municípios ativos do estado, indexados pelo id
     * @return array<string, mixed>
     */
    public function construir(Indicador $indicador, string $competenciaRotulo, array $valores, Collection $municipios, ?int $selecionado, int $codigoUf): array
    {
        $faixas = $this->faixas($indicador, array_values($valores));
        $regioes = [];

        foreach ($municipios as $id => $municipio) {
            $tem = array_key_exists($id, $valores);
            $classe = $tem ? $this->classeDe($valores[$id], $faixas) : null;

            $regioes[] = [
                'name' => (string) $id,
                'id' => $id,
                'nome' => $municipio->nome,
                'valor' => $tem ? $valores[$id] : null,
                'texto' => $tem ? Formatador::valor($valores[$id], $indicador) : 'Sem dado nesta competência',
                'cor' => $classe === null ? self::SEM_DADO : $faixas[$classe]['cor'],
            ];
        }

        // O município escolhido vai por último para o contorno dele ficar por cima dos vizinhos.
        usort($regioes, fn (array $a, array $b): int => ($a['id'] === $selecionado) <=> ($b['id'] === $selecionado));

        return [
            'modo' => 'mapa',
            'malha' => route('malha', ['uf' => $codigoUf], false),
            'mapa' => "uf-{$codigoUf}",
            'regioes' => $regioes,
            'selecionado' => $selecionado === null ? null : (string) $selecionado,
            'faixas' => array_map(fn (array $faixa): array => ['rotulo' => $faixa['rotulo'], 'cor' => $faixa['cor']], $faixas),
            'sem_dado' => $municipios->count() - count($valores),
            'competencia' => $competenciaRotulo,
        ];
    }

    /**
     * Linhas da tabela equivalente ao mapa, da melhor para a pior situação (indicadores neutros: do maior para o menor).
     *
     * @param  array<int, float>  $valores
     * @param  Collection<int, Municipio>  $municipios
     * @return array{colunas: list<string>, linhas: list<list<string>>, ids: list<int>}
     */
    public function tabela(Indicador $indicador, array $valores, Collection $municipios): array
    {
        $ordenados = $valores;
        $indicador->polaridade === Polaridade::MenorMelhor ? asort($ordenados) : arsort($ordenados);

        $doEstado = collect($ordenados)->filter(fn (float $valor, int $id): bool => $municipios->has($id));

        return [
            'colunas' => ['Município', 'Região de saúde', 'Valor'],
            'linhas' => $doEstado
                ->map(fn (float $valor, int $id): array => [
                    $municipios[$id]->nome,
                    mb_convert_case((string) $municipios[$id]->regiao_saude_nome, MB_CASE_TITLE),
                    Formatador::valor($valor, $indicador),
                ])
                ->values()
                ->all(),
            'ids' => $doEstado->keys()->map(fn ($id): int => (int) $id)->all(),
        ];
    }

    /**
     * Até cinco faixas de mesmo tamanho (quintis); valores repetidos podem reduzir a quantidade de faixas.
     *
     * @param  list<float>  $valores
     * @return list<array{limite: float, rotulo: string, cor: string}>
     */
    private function faixas(Indicador $indicador, array $valores): array
    {
        if ($valores === []) {
            return [];
        }

        sort($valores);

        $limites = [];

        foreach ([0.2, 0.4, 0.6, 0.8, 1.0] as $proporcao) {
            $limite = round(CalculadorDeBenchmarks::percentil($valores, $proporcao), 4);

            if ($limites === [] || $limite > $limites[array_key_last($limites)]) {
                $limites[] = $limite;
            }
        }

        $cores = $this->cores(count($limites));
        $faixas = [];
        $inferior = round($valores[0], 4);

        foreach ($limites as $posicao => $limite) {
            $faixas[] = [
                'limite' => $limite,
                'rotulo' => $inferior == $limite ? $this->curto($limite, $indicador) : $this->curto($inferior, $indicador).' a '.$this->curto($limite, $indicador),
                'cor' => $cores[$posicao],
            ];

            $inferior = $limite;
        }

        return $faixas;
    }

    /**
     * @return list<string>
     */
    private function cores(int $quantidade): array
    {
        if ($quantidade >= count(self::RAMPA)) {
            return self::RAMPA;
        }

        if ($quantidade === 1) {
            return [self::RAMPA[2]];
        }

        $escolhidas = [];

        for ($i = 0; $i < $quantidade; $i++) {
            $escolhidas[] = self::RAMPA[(int) round($i * (count(self::RAMPA) - 1) / ($quantidade - 1))];
        }

        return $escolhidas;
    }

    /**
     * @param  list<array{limite: float, rotulo: string, cor: string}>  $faixas
     */
    private function classeDe(float $valor, array $faixas): int
    {
        foreach ($faixas as $posicao => $faixa) {
            if (round($valor, 4) <= $faixa['limite']) {
                return $posicao;
            }
        }

        return array_key_last($faixas);
    }

    private function curto(float $valor, Indicador $indicador): string
    {
        $numero = Formatador::numero($valor, $indicador->casas_decimais);

        return $indicador->unidade === '%' ? $numero.'%' : $numero;
    }
}
