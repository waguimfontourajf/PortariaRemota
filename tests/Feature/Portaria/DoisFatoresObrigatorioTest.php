<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Models\Condominio;
use App\Models\User;
use Database\Seeders\PerfisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoisFatoresObrigatorioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PerfisSeeder::class);
    }

    public function test_super_admin_sem_2fa_e_levado_para_a_tela_de_seguranca(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertRedirect(route('security.edit'))
            ->assertSessionHas('aviso_2fa');
    }

    public function test_super_admin_com_2fa_usa_o_sistema(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    }

    public function test_operador_sem_2fa_e_bloqueado_em_qualquer_condominio(): void
    {
        $condominio = Condominio::factory()->create();
        $operador = User::factory()->create();
        $operador->atribuirPerfil(Perfil::Operador, $condominio);

        $this->actingAs($operador)
            ->get(route('dashboard'))
            ->assertRedirect(route('security.edit'));
    }

    public function test_morador_nao_precisa_de_2fa(): void
    {
        $condominio = Condominio::factory()->create();
        $morador = User::factory()->create();
        $morador->atribuirPerfil(Perfil::Morador, $condominio);

        // Morador vai direto para "Abrir" (sem pedir 2FA).
        $this->actingAs($morador)->get(route('dashboard'))->assertRedirect(route('acessos.abrir'));
        $this->get(route('acessos.abrir'))->assertOk();
    }

    public function test_exigencia_pode_ser_desligada_na_configuracao(): void
    {
        config(['portaria.exigir_2fa' => false]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_tela_de_perfil_continua_acessivel_sem_2fa(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('profile.edit'))
            ->assertOk();
    }
}
