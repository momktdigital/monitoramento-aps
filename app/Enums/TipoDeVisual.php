<?php

namespace App\Enums;

enum TipoDeVisual: string
{
    case Destaque = 'destaque';
    case Evolucao = 'evolucao';
    case Ranking = 'ranking';

    public function rotulo(): string
    {
        return match ($this) {
            self::Destaque => 'Destaque',
            self::Evolucao => 'Evolução',
            self::Ranking => 'Ranking',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Destaque => 'Valor atual, comparação com a região e tendência',
            self::Evolucao => 'Linha do tempo comparada à região e ao estado',
            self::Ranking => 'Posição entre os municípios da região de saúde',
        };
    }

    /**
     * Largura inicial (em colunas de 3) quando o visual é adicionado.
     */
    public function larguraInicial(): int
    {
        return match ($this) {
            self::Destaque => 1,
            self::Evolucao, self::Ranking => 2,
        };
    }
}
