<?php

namespace App\Enums;

/**
 * Relação de uma pessoa com uma unidade.
 */
enum VinculoUnidade: string
{
    case Proprietario = 'proprietario';
    case Inquilino = 'inquilino';
    case Dependente = 'dependente';
    case Funcionario = 'funcionario';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Proprietario => 'Proprietário',
            self::Inquilino => 'Inquilino',
            self::Dependente => 'Dependente / familiar',
            self::Funcionario => 'Funcionário da unidade',
            self::Outro => 'Outro',
        };
    }
}
