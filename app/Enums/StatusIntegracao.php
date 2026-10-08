<?php

namespace App\Enums;

enum StatusIntegracao: string
{
    case Nunca = 'nunca';
    case NaFila = 'na_fila';
    case Executando = 'executando';
    case Ok = 'ok';
    case Erro = 'erro';

    public function rotulo(): string
    {
        return match ($this) {
            self::Nunca => 'Nunca executada',
            self::NaFila => 'Na fila',
            self::Executando => 'Atualizando',
            self::Ok => 'Atualizada',
            self::Erro => 'Falhou',
        };
    }

    public function emAndamento(): bool
    {
        return $this === self::NaFila || $this === self::Executando;
    }
}
