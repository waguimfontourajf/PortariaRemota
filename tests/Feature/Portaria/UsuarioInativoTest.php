<?php

namespace Tests\Feature\Portaria;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuarioInativoTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_inativo_nao_consegue_entrar(): void
    {
        $usuario = User::factory()->inativo()->create();

        $this->post(route('login.store'), [
            'email' => $usuario->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_desativado_durante_a_sessao_e_desconectado(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario)->get(route('dashboard'))->assertOk();

        $usuario->forceFill(['ativo' => false])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_registra_ultimo_acesso(): void
    {
        $usuario = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $usuario->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertNotNull($usuario->fresh()->ultimo_login_em);
    }
}
