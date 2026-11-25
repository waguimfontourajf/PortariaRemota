<?php

namespace App\Enums;

enum TipoDispositivo: string
{
    case Rele = 'rele';
    case Camera = 'camera';
    case Interfone = 'interfone';
    case Controladora = 'controladora';

    public function label(): string
    {
        return match ($this) {
            self::Rele => 'Relé / acionador',
            self::Camera => 'Câmera',
            self::Interfone => 'Interfone',
            self::Controladora => 'Controladora de acesso',
        };
    }
}
