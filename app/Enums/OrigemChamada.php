<?php

namespace App\Enums;

enum OrigemChamada: string
{
    case Interfone = 'interfone';
    case Campainha = 'campainha';
    case Teste = 'teste';

    public function label(): string
    {
        return match ($this) {
            self::Interfone => 'Interfone',
            self::Campainha => 'Botão / campainha',
            self::Teste => 'Teste',
        };
    }
}
