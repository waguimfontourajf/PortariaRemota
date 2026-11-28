<?php

namespace App\Enums;

enum TipoDispositivo: string
{
    case Rele = 'rele';
    case Controladora = 'controladora';
    case Camera = 'camera';
    case Interfone = 'interfone';
    case Roteador = 'roteador';
    case Switch = 'switch';
    case AccessPoint = 'access_point';
    case Servidor = 'servidor';
    case Computador = 'computador';
    case Ata = 'ata';
    case Nobreak = 'nobreak';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Rele => 'Relé / acionador',
            self::Controladora => 'Controladora / leitora de acesso',
            self::Camera => 'Câmera',
            self::Interfone => 'Interfone',
            self::Roteador => 'Roteador',
            self::Switch => 'Switch',
            self::AccessPoint => 'Access point (Wi-Fi)',
            self::Servidor => 'Servidor',
            self::Computador => 'Computador',
            self::Ata => 'ATA (adaptador de telefone)',
            self::Nobreak => 'Nobreak',
            self::Outro => 'Outro',
        };
    }

    /** Ícone (Heroicons) usado nas listas e no mapa. */
    public function icone(): string
    {
        return match ($this) {
            self::Rele => 'bolt',
            self::Controladora => 'finger-print',
            self::Camera => 'video-camera',
            self::Interfone => 'phone',
            self::Roteador => 'globe-alt',
            self::Switch => 'server-stack',
            self::AccessPoint => 'wifi',
            self::Servidor => 'server',
            self::Computador => 'computer-desktop',
            self::Ata => 'phone-arrow-up-right',
            self::Nobreak => 'battery-100',
            self::Outro => 'cpu-chip',
        };
    }

    /** Tipos acionados por um driver (relé, leitora). */
    public function usaDriver(): bool
    {
        return in_array($this, [self::Rele, self::Controladora], true);
    }
}
