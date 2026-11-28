<?php

namespace App\Enums;

enum StatusChamada: string
{
    case Tocando = 'tocando';
    case EmAtendimento = 'em_atendimento';
    case Encerrada = 'encerrada';
    case Perdida = 'perdida';

    public function label(): string
    {
        return match ($this) {
            self::Tocando => 'Tocando',
            self::EmAtendimento => 'Em atendimento',
            self::Encerrada => 'Atendida',
            self::Perdida => 'Não atendida',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Tocando => 'amber',
            self::EmAtendimento => 'blue',
            self::Encerrada => 'green',
            self::Perdida => 'red',
        };
    }

    public function ativa(): bool
    {
        return in_array($this, [self::Tocando, self::EmAtendimento], true);
    }
}
