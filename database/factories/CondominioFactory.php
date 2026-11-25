<?php

namespace Database\Factories;

use App\Enums\ModoOperacao;
use App\Models\Condominio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Condominio>
 */
class CondominioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nome' => 'Condomínio '.fake()->unique()->lastName(),
            'telefone' => fake()->numerify('(##) ####-####'),
            'cep' => fake()->numerify('#####-###'),
            'logradouro' => fake()->streetName(),
            'numero' => (string) fake()->buildingNumber(),
            'bairro' => fake()->word(),
            'cidade' => fake()->city(),
            'uf' => 'SP',
            'modo_operacao' => ModoOperacao::Direto,
            'ativo' => true,
        ];
    }

    public function comServidorLocal(): static
    {
        return $this->state(fn () => ['modo_operacao' => ModoOperacao::Local]);
    }

    public function inativo(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }
}
