<?php

namespace App\Dispositivos;

use App\Dispositivos\Contratos\DriverDeRele;
use InvalidArgumentException;

/**
 * Lista de drivers disponíveis (config/portaria.php > drivers.reles).
 */
class RegistroDeDrivers
{
    /** @var array<string, class-string<DriverDeRele>> */
    private array $reles = [];

    /**
     * @param  list<class-string<DriverDeRele>>  $classes
     */
    public function __construct(array $classes)
    {
        foreach ($classes as $classe) {
            $this->reles[$classe::chave()] = $classe;
        }
    }

    public function existe(string $chave): bool
    {
        return isset($this->reles[$chave]);
    }

    public function rele(string $chave): DriverDeRele
    {
        $classe = $this->reles[$chave] ?? throw new InvalidArgumentException("Driver de relé desconhecido: {$chave}");

        return app($classe);
    }

    /**
     * @return class-string<DriverDeRele>
     */
    public function classe(string $chave): string
    {
        return $this->reles[$chave] ?? throw new InvalidArgumentException("Driver de relé desconhecido: {$chave}");
    }

    /**
     * @return array<string, class-string<DriverDeRele>>
     */
    public function reles(): array
    {
        return $this->reles;
    }
}
