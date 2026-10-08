<?php

namespace App\Integrations;

final readonly class ResultadoDoTeste
{
    public function __construct(public bool $ok, public string $mensagem) {}

    public static function sucesso(string $mensagem): self
    {
        return new self(true, $mensagem);
    }

    public static function falha(string $mensagem): self
    {
        return new self(false, $mensagem);
    }
}
