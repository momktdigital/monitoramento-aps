<?php

namespace App\Enums;

enum StatusIngestao: string
{
    case Executando = 'executando';
    case Sucesso = 'sucesso';
    case Parcial = 'parcial';
    case Erro = 'erro';

    public function rotulo(): string
    {
        return match ($this) {
            self::Executando => 'Em andamento',
            self::Sucesso => 'Concluída',
            self::Parcial => 'Concluída com avisos',
            self::Erro => 'Falhou',
        };
    }
}
