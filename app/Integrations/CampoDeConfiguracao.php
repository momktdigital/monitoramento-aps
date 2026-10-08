<?php

namespace App\Integrations;

/**
 * Descrição de um parâmetro editável de uma integração.
 * Campos `secreto` são criptografados, nunca reexibidos e só podem ser substituídos.
 */
final readonly class CampoDeConfiguracao
{
    public function __construct(
        public string $nome,
        public string $rotulo,
        public bool $secreto = false,
        public bool $obrigatorio = false,
        public string $tipo = 'texto',
        public string|int|null $padrao = null,
        public ?string $ajuda = null,
        public ?int $minimo = null,
        public ?int $maximo = null,
    ) {}

    /**
     * Regras de validação do valor informado na tela.
     *
     * @return list<string>
     */
    public function regras(): array
    {
        if ($this->tipo === 'numero') {
            return ['nullable', 'integer', 'min:'.($this->minimo ?? 0), 'max:'.($this->maximo ?? 1000)];
        }

        return ['nullable', 'string', 'max:200', 'regex:/^[\x21-\x7E]+$/'];
    }
}
