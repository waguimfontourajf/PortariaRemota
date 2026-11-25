<?php

namespace Database\Factories;

use App\Models\Condominio;
use App\Models\Dispositivo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispositivo>
 */
class DispositivoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'condominio_id' => Condominio::factory(),
            'nome' => 'Relé '.fake()->word(),
            'tipo' => 'rele',
            'driver' => 'simulador',
            'canais' => 2,
            'config' => [],
            'ativo' => true,
        ];
    }

    public function shelly(string $host = '192.168.0.50'): static
    {
        return $this->state(fn () => ['driver' => 'shelly', 'host' => $host, 'config' => ['geracao' => 'gen2']]);
    }

    public function simuladorComFalha(): static
    {
        return $this->state(fn () => ['driver' => 'simulador', 'config' => ['falhar' => true]]);
    }
}
