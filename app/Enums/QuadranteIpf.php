<?php

namespace App\Enums;

/**
 * Quadrante da matriz INA × IDAPS (Índice de Potencial de Financiamento). O valor numérico é gravado em `indices_municipio`.
 */
enum QuadranteIpf: int
{
    case PrioridadeMaxima = 1;
    case GrandePotencial = 2;
    case OportunidadeModerada = 3;
    case EstruturaConsolidada = 4;

    /**
     * @return string chave usada na configuração (`config/indices.php`)
     */
    public function chave(): string
    {
        return match ($this) {
            self::PrioridadeMaxima => 'prioridade_maxima',
            self::GrandePotencial => 'grande_potencial',
            self::OportunidadeModerada => 'oportunidade_moderada',
            self::EstruturaConsolidada => 'estrutura_consolidada',
        };
    }

    public static function daChave(string $chave): self
    {
        foreach (self::cases() as $caso) {
            if ($caso->chave() === $chave) {
                return $caso;
            }
        }

        throw new \InvalidArgumentException("Quadrante desconhecido: {$chave}");
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::PrioridadeMaxima => 'Prioridade máxima',
            self::GrandePotencial => 'Grande potencial',
            self::OportunidadeModerada => 'Oportunidade moderada',
            self::EstruturaConsolidada => 'Estrutura consolidada',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::PrioridadeMaxima => 'Necessidade alta e desempenho baixo: a população precisa mais e a APS entrega menos.',
            self::GrandePotencial => 'Necessidade alta e desempenho alto: a APS mostra capacidade de transformar recursos em resultado onde há mais demanda.',
            self::OportunidadeModerada => 'Necessidade baixa e desempenho baixo: há espaço para melhorar, com menos urgência.',
            self::EstruturaConsolidada => 'Necessidade baixa e desempenho alto: a APS está bem estruturada para a demanda atual.',
        };
    }
}
