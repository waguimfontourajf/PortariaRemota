<?php

namespace App\Enums;

enum TipoLigacao: string
{
    case Cabo = 'cabo';
    case Wireless = 'wireless';
    case Fibra = 'fibra';
    case Vpn = 'vpn';

    public function label(): string
    {
        return match ($this) {
            self::Cabo => 'Cabo de rede',
            self::Wireless => 'Wireless (Wi-Fi / rádio)',
            self::Fibra => 'Fibra óptica',
            self::Vpn => 'VPN',
        };
    }
}
