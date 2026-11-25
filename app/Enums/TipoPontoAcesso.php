<?php

namespace App\Enums;

enum TipoPontoAcesso: string
{
    case PortaoSocial = 'portao_social';
    case PortaoGaragem = 'portao_garagem';
    case Porta = 'porta';
    case Eclusa = 'eclusa';
    case Cancela = 'cancela';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::PortaoSocial => 'Portão social (pedestres)',
            self::PortaoGaragem => 'Portão de garagem',
            self::Porta => 'Porta',
            self::Eclusa => 'Eclusa',
            self::Cancela => 'Cancela',
            self::Outro => 'Outro',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::PortaoSocial => 'user',
            self::PortaoGaragem => 'truck',
            self::Porta => 'key',
            self::Eclusa => 'arrows-right-left',
            self::Cancela => 'minus',
            self::Outro => 'bolt',
        };
    }
}
