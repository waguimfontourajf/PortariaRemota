<?php

namespace App\Enums;

enum AcaoEvento: string
{
    case Abrir = 'abrir';
    case Ligar = 'ligar';
    case Desligar = 'desligar';
    case Testar = 'testar';

    public function label(): string
    {
        return match ($this) {
            self::Abrir => 'Abertura',
            self::Ligar => 'Ligou',
            self::Desligar => 'Desligou',
            self::Testar => 'Teste de comunicação',
        };
    }
}
