<?php

namespace App\Enums;

/**
 * Como um condomínio opera.
 */
enum ModoOperacao: string
{
    /** Sem servidor no condomínio: tudo é decidido pelo servidor central. */
    case Direto = 'direto';

    /** Com servidor local licenciado: as decisões são tomadas no condomínio. */
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::Direto => 'Direto (servidor central)',
            self::Local => 'Com servidor local',
        };
    }
}
