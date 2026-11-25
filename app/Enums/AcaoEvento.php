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
            self::Ligar => 'Ligar',
            self::Desligar => 'Desligar',
            self::Testar => 'Teste de comunicação',
        };
    }
}
