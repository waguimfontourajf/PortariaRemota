<?php

namespace App\Enums;

enum ResultadoEvento: string
{
    case Sucesso = 'sucesso';
    case Falha = 'falha';
    case Negado = 'negado';
    case Ocupado = 'ocupado';

    public function label(): string
    {
        return match ($this) {
            self::Sucesso => 'Sucesso',
            self::Falha => 'Falha',
            self::Negado => 'Negado',
            self::Ocupado => 'Ignorado (comando repetido)',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Sucesso => 'green',
            self::Falha => 'red',
            self::Negado => 'amber',
            self::Ocupado => 'zinc',
        };
    }
}
