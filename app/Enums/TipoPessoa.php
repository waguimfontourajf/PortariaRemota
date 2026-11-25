<?php

namespace App\Enums;

enum TipoPessoa: string
{
    case Morador = 'morador';
    case Funcionario = 'funcionario';
    case Prestador = 'prestador';

    public function label(): string
    {
        return match ($this) {
            self::Morador => 'Morador',
            self::Funcionario => 'Funcionário do condomínio',
            self::Prestador => 'Prestador de serviço',
        };
    }
}
