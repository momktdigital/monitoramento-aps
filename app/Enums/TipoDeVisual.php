<?php

namespace App\Enums;

use App\Models\Indicador;

enum TipoDeVisual: string
{
    case Destaque = 'destaque';
    case Evolucao = 'evolucao';
    case Ranking = 'ranking';
    case Mapa = 'mapa';
    case Matriz = 'matriz';

    /** Indicador ao qual a matriz INA × IDAPS está ligada (a pontuação de prioridade que ela ordena). */
    public const INDICADOR_DA_MATRIZ = 'indice_prioridade';

    public function rotulo(): string
    {
        return match ($this) {
            self::Destaque => 'Destaque',
            self::Evolucao => 'Evolução',
            self::Ranking => 'Ranking',
            self::Mapa => 'Mapa',
            self::Matriz => 'Matriz',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Destaque => 'Valor atual, posição na região e tendência',
            self::Evolucao => 'Linha do tempo comparada à região e ao estado',
            self::Ranking => 'Posição entre os municípios, clicável',
            self::Mapa => 'Os municípios do estado coloridos pelo valor',
            self::Matriz => 'Necessidade × desempenho de todos os municípios',
        };
    }

    /**
     * Frase curta, sob o gráfico, que diz o que dá para fazer com ele.
     */
    public function dica(): string
    {
        return match ($this) {
            self::Destaque => '',
            self::Evolucao => 'Passe o mouse sobre a linha para ver cada mês e a variação. Clique na legenda para esconder ou mostrar uma linha; arraste a barra abaixo do gráfico para ampliar um período.',
            self::Ranking => 'Clique em um município para colocá-lo em foco em todo o painel. Use Região ou Estado para mudar a comparação.',
            self::Mapa => 'Passe o mouse para ver o valor de cada município. Clique em um município para colocá-lo em foco em todo o painel.',
            self::Matriz => 'Passe o mouse sobre um ponto para ver o município. Clique para colocá-lo em foco em todo o painel.',
        };
    }

    /**
     * Largura inicial (em colunas de 3) quando o visual é adicionado. Destaque + evolução, ou ranking + mapa,
     * completam uma linha.
     */
    public function larguraInicial(): int
    {
        return match ($this) {
            self::Destaque, self::Ranking => 1,
            self::Evolucao, self::Mapa, self::Matriz => 2,
        };
    }

    /**
     * Informa se o tipo faz sentido para o indicador: a matriz só existe para a prioridade (IPF); os demais servem a todos.
     */
    public function aplicaA(Indicador|string $indicador): bool
    {
        $codigo = $indicador instanceof Indicador ? $indicador->codigo : $indicador;

        return $this !== self::Matriz || $codigo === self::INDICADOR_DA_MATRIZ;
    }

    /**
     * Tipos que podem ser adicionados a um indicador, na ordem em que aparecem na galeria.
     *
     * @return list<self>
     */
    public static function paraIndicador(Indicador|string $indicador): array
    {
        return array_values(array_filter(self::cases(), fn (self $tipo): bool => $tipo->aplicaA($indicador)));
    }
}
