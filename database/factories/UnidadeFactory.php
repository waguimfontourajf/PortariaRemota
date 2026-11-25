<?php

namespace Database\Factories;

use App\Enums\TipoUnidade;
use App\Models\Condominio;
use App\Models\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unidade>
 */
class UnidadeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'condominio_id' => Condominio::factory(),
            'bloco_id' => null,
            'numero' => (string) fake()->unique()->numberBetween(1, 9999),
            'tipo' => TipoUnidade::Apartamento,
        ];
    }
}
