<?php

namespace App\Enums;

enum OrigemEvento: string
{
    case Web = 'web';
    case App = 'app';
    case Central = 'central';
    case Local = 'local';
    case Interfone = 'interfone';
    case Api = 'api';
    case Sistema = 'sistema';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Painel web',
            self::App => 'Aplicativo',
            self::Central => 'Central de atendimento',
            self::Local => 'Servidor local',
            self::Interfone => 'Interfone',
            self::Api => 'API',
            self::Sistema => 'Sistema',
        };
    }
}
