<?php

namespace App\Enums;

/**
 * Em qual papel esta instalação do sistema está rodando.
 */
enum ModoServidor: string
{
    case Central = 'central';
    case Local = 'local';

    /**
     * Modo configurado nesta instalação (PORTARIA_MODO no .env).
     */
    public static function atual(): self
    {
        return self::tryFrom((string) config('portaria.modo')) ?? self::Central;
    }

    public function label(): string
    {
        return match ($this) {
            self::Central => 'Servidor central',
            self::Local => 'Servidor local (condomínio)',
        };
    }
}
