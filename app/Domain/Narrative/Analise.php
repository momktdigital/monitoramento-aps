<?php

namespace App\Domain\Narrative;

/**
 * Texto de análise de um visual: um tom (favorável, atenção...) e parágrafos em linguagem simples.
 */
final readonly class Analise
{
    public const FAVORAVEL = 'favoravel';

    public const ATENCAO = 'atencao';

    public const INTERMEDIARIO = 'intermediario';

    public const INFORMATIVO = 'informativo';

    public const SEM_DADO = 'sem_dado';

    /**
     * @param  list<string>  $paragrafos
     */
    public function __construct(public string $tom, public array $paragrafos) {}

    public function rotuloDoTom(): string
    {
        return match ($this->tom) {
            self::FAVORAVEL => 'Situação favorável',
            self::ATENCAO => 'Merece atenção',
            self::INTERMEDIARIO => 'Situação intermediária',
            self::SEM_DADO => 'Sem dados',
            default => 'Informativo',
        };
    }

    /**
     * @return array{tom: string, paragrafos: list<string>}
     */
    public function toArray(): array
    {
        return ['tom' => $this->tom, 'paragrafos' => $this->paragrafos];
    }

    /**
     * @param  array{tom: string, paragrafos: list<string>}  $dados
     */
    public static function fromArray(array $dados): self
    {
        return new self($dados['tom'], $dados['paragrafos']);
    }
}
