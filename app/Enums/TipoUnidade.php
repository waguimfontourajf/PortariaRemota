<?php

namespace App\Enums;

enum TipoUnidade: string
{
    case Apartamento = 'apartamento';
    case Casa = 'casa';
    case Sala = 'sala';
    case Loja = 'loja';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Apartamento => 'Apartamento',
            self::Casa => 'Casa',
            self::Sala => 'Sala comercial',
            self::Loja => 'Loja',
            self::Outro => 'Outro',
        };
    }
}
