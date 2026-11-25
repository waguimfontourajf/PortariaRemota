<?php

namespace App\Enums;

enum ModoAcionamento: string
{
    /** Liga e desliga sozinho depois do tempo de pulso (padrão para portões). */
    case Pulso = 'pulso';

    /** Alterna entre ligado e desligado a cada comando (ex.: iluminação). */
    case Alternar = 'alternar';

    public function label(): string
    {
        return match ($this) {
            self::Pulso => 'Pulso (abre e fecha sozinho)',
            self::Alternar => 'Liga / desliga',
        };
    }
}
