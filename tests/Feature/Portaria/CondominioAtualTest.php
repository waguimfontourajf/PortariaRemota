<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Enums\Permissao;
use App\Models\Condominio;
use App\Models\User;
use App\Support\CondominioAtual;
use Database\Seeders\PerfisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CondominioAtualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PerfisSeeder::class);
    }

    public function test_raiz_redireciona_para_o_painel_e_visitante_vai_para_o_login(): void
    {
        $this->get('/')->assertRedirect('/painel');
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_usuario_sem_condominio_ve_aviso(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Seu usuário ainda não está vinculado a nenhum condomínio.');
    }

    public function test_usuario_so_enxerga_os_condominios_em_que_tem_perfil(): void
    {
        [$a, $b] = Condominio::factory()->count(2)->create();
        $usuario = User::factory()->create();
        $usuario->atribuirPerfil(Perfil::Sindico, $a);

        $this->assertTrue($usuario->podeAcessarCondominio($a));
        $this->assertFalse($usuario->podeAcessarCondominio($b));

        $this->actingAs($usuario)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($a->nome)
            ->assertDontSee($b->nome)
            ->assertSessionHas(CondominioAtual::CHAVE_SESSAO, $a->id);
    }

    public function test_nao_pode_selecionar_condominio_sem_acesso(): void
    {
        [$a, $b] = Condominio::factory()->count(2)->create();
        $usuario = User::factory()->create();
        $usuario->atribuirPerfil(Perfil::Morador, $a);

        $this->actingAs($usuario)
            ->post(route('condominio.selecionar', $b))
            ->assertForbidden();
    }

    public function test_troca_de_condominio_muda_o_escopo_das_permissoes(): void
    {
        [$a, $b] = Condominio::factory()->count(2)->create();
        $usuario = User::factory()->create();
        $usuario->atribuirPerfil(Perfil::Sindico, $a);
        $usuario->atribuirPerfil(Perfil::Morador, $b);

        $this->actingAs($usuario)
            ->post(route('condominio.selecionar', $a))
            ->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertSee('Síndico / administrador');
        $this->assertTrue($usuario->fresh()->noCondominio($a, fn () => $usuario->can(Permissao::CadastrosGerenciar->value)));

        $this->post(route('condominio.selecionar', $b))
            ->assertSessionHas(CondominioAtual::CHAVE_SESSAO, $b->id);
        $this->get(route('dashboard'))->assertSee('Morador')->assertDontSee('Síndico / administrador');
        $this->assertFalse($usuario->fresh()->noCondominio($b, fn () => $usuario->can(Permissao::CadastrosGerenciar->value)));
    }

    public function test_condominio_inativo_nao_fica_acessivel(): void
    {
        $inativo = Condominio::factory()->inativo()->create();
        $usuario = User::factory()->create();
        $usuario->atribuirPerfil(Perfil::Sindico, $inativo);

        $this->assertFalse($usuario->podeAcessarCondominio($inativo));
    }

    public function test_super_admin_acessa_qualquer_condominio_e_tem_todas_as_permissoes(): void
    {
        $condominio = Condominio::factory()->create();
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)
            ->post(route('condominio.selecionar', $condominio))
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertSee($condominio->nome)->assertSee('Super admin');
        $this->assertTrue($admin->can(Permissao::DispositivosGerenciar->value));
    }

    public function test_troca_de_condominio_fica_registrada_na_auditoria(): void
    {
        $condominio = Condominio::factory()->create();
        $usuario = User::factory()->create();
        $usuario->atribuirPerfil(Perfil::Morador, $condominio);

        $this->actingAs($usuario)->post(route('condominio.selecionar', $condominio));

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'acesso_sistema',
            'causer_id' => $usuario->id,
            'subject_id' => $condominio->id,
        ]);
    }
}
