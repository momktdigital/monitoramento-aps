<?php

namespace App\Enums;

enum Perfil: string
{
    case Admin = 'admin';
    case Gestor = 'gestor';
    case Leitura = 'leitura';

    public function rotulo(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Gestor => 'Gestor',
            self::Leitura => 'Leitura',
        };
    }

    /**
     * Perfis com poder de alterar configurações exigem verificação em duas etapas.
     */
    public function exigeDoisFatores(): bool
    {
        return $this === self::Admin;
    }
}
