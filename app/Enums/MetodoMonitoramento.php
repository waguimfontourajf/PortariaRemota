<?php

namespace App\Enums;

enum MetodoMonitoramento: string
{
    case Ping = 'ping';
    case Tcp = 'tcp';
    case Driver = 'driver';

    public function label(): string
    {
        return match ($this) {
            self::Ping => 'Ping (ICMP)',
            self::Tcp => 'Porta TCP',
            self::Driver => 'Pelo driver (teste de comunicação)',
        };
    }
}
