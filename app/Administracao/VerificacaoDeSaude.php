<?php

namespace App\Administracao;

/**
 * Resultado de uma verificação do sistema: "ok", "atencao" ou "erro", com o detalhe e, quando útil, o que fazer.
 */
final readonly class VerificacaoDeSaude
{
    public const OK = 'ok';

    public const ATENCAO = 'atencao';

    public const ERRO = 'erro';

    public function __construct(
        public string $grupo,
        public string $nome,
        public string $estado,
        public string $detalhe,
        public ?string $dica = null,
    ) {}

    public static function ok(string $grupo, string $nome, string $detalhe): self
    {
        return new self($grupo, $nome, self::OK, $detalhe);
    }

    public static function atencao(string $grupo, string $nome, string $detalhe, ?string $dica = null): self
    {
        return new self($grupo, $nome, self::ATENCAO, $detalhe, $dica);
    }

    public static function erro(string $grupo, string $nome, string $detalhe, ?string $dica = null): self
    {
        return new self($grupo, $nome, self::ERRO, $detalhe, $dica);
    }

    public function rotuloDoEstado(): string
    {
        return match ($this->estado) {
            self::OK => 'Tudo certo',
            self::ATENCAO => 'Atenção',
            default => 'Problema',
        };
    }
}
