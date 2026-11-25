<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Models\Condominio;
use App\Models\User;
use App\Support\CondominioAtual;
use Database\Seeders\PerfisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class CadastrosTestCase extends TestCase
{
    use RefreshDatabase;

    protected Condominio $condominio;

    protected Condominio $outro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PerfisSeeder::class);
        $this->condominio = Condominio::factory()->create(['nome' => 'Residencial Alfa']);
        $this->outro = Condominio::factory()->create(['nome' => 'Residencial Beta']);
    }

    protected function usuarioCom(Perfil $perfil, ?Condominio $condominio = null): User
    {
        $usuario = User::factory()->withTwoFactor()->create();
        $usuario->atribuirPerfil($perfil, $condominio ?? $this->condominio);

        return $usuario;
    }

    /**
     * Autentica e seleciona o condomínio (como o middleware faria).
     */
    protected function entrarComo(User $usuario, ?Condominio $condominio = null): static
    {
        $this->actingAs($usuario)->withSession([
            CondominioAtual::CHAVE_SESSAO => ($condominio ?? $this->condominio)->id,
        ]);

        app(CondominioAtual::class)->resolver($usuario);

        return $this;
    }
}
