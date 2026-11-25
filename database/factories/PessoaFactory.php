<?php

namespace Database\Factories;

use App\Enums\TipoPessoa;
use App\Models\Condominio;
use App\Models\Pessoa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pessoa>
 */
class PessoaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'condominio_id' => Condominio::factory(),
            'nome' => fake()->name(),
            'tipo' => TipoPessoa::Morador,
            'cpf' => fake('pt_BR')->cpf(),
            'telefone' => fake()->numerify('(##) 9####-####'),
            'email' => fake()->safeEmail(),
            'ativo' => true,
        ];
    }
}
