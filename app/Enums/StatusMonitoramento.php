<?php

namespace App\Enums;

enum StatusMonitoramento: string
{
    case Online = 'online';
    case Lento = 'lento';
    case Offline = 'offline';
    case Desconhecido = 'desconhecido';
    case NaoMonitorado = 'nao_monitorado';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Lento => 'Lento',
            self::Offline => 'Offline',
            self::Desconhecido => 'Aguardando verificação',
            self::NaoMonitorado => 'Não monitorado',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Online => 'green',
            self::Lento => 'amber',
            self::Offline => 'red',
            default => 'zinc',
        };
    }
}
