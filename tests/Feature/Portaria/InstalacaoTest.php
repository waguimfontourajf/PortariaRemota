<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Enums\Permissao;
use App\Models\User;
use Database\Seeders\PerfisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstalacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_de_perfis_cria_tudo_e_pode_rodar_de_novo(): void
    {
        $this->seed(PerfisSeeder::class);
        $this->seed(PerfisSeeder::class);

        $this->assertSame(count(Permissao::cases()), Permission::count());
        $this->assertSame(count(Perfil::cases()), Role::count());

        $sindico = Role::findByName(Perfil::Sindico->value, 'web');
        $this->assertTrue($sindico->hasPermissionTo(Permissao::CadastrosGerenciar->value));
    }

    public function test_comando_cria_super_admin(): void
    {
        $this->artisan('portaria:criar-admin', [
            '--nome' => 'Admin Teste',
            '--email' => 'admin@teste.com.br',
            '--senha' => 'senha-forte-123',
        ])->assertSuccessful();

        $admin = User::where('email', 'admin@teste.com.br')->first();
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertNotNull($admin->uuid);
    }

    public function test_comando_recusa_senha_curta(): void
    {
        $this->artisan('portaria:criar-admin', [
            '--nome' => 'Admin',
            '--email' => 'admin@teste.com.br',
            '--senha' => '123',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'admin@teste.com.br']);
    }

    public function test_seeder_cria_super_admin_a_partir_do_env(): void
    {
        config([
            'portaria.admin.email' => 'dono@meusistema.api.br',
            'portaria.admin.senha' => 'senha-forte-123',
        ]);

        $this->seed();

        $this->assertTrue(User::where('email', 'dono@meusistema.api.br')->first()->isSuperAdmin());
    }
}
