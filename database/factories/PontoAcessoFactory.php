<?php

namespace Database\Factories;

use App\Models\Condominio;
use App\Models\Dispositivo;
use App\Models\PontoAcesso;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PontoAcesso>
 */
class PontoAcessoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'condominio_id' => Condominio::factory(),
            'nome' => 'Portão '.fake()->word(),
            'tipo' => 'portao_social',
            'dispositivo_id' => fn (array $a) => Dispositivo::factory()->create(['condominio_id' => $a['condominio_id']])->id,
            'canal' => 1,
            'tempo_pulso_ms' => 200,
            'liberado_moradores' => false,
            'ativo' => true,
        ];
    }
}
