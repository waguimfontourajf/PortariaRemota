<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Models\Pessoa;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

class UsuariosTest extends CadastrosTestCase
{
    public function test_sindico_cria_usuario_morador_vinculado_a_pessoa(): void
    {
        $pessoa = Pessoa::factory()->for($this->condominio)->create();
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::usuarios.form')
            ->set('name', 'Carlos Morador')
            ->set('email', 'Carlos@Exemplo.com')
            ->set('password', 'senha-segura-1')
            ->set('perfis', ['morador'])
            ->set('pessoa_id', (string) $pessoa->id)
            ->call('salvar')
            ->assertHasNoErrors();

        $usuario = User::where('email', 'carlos@exemplo.com')->firstOrFail();
        $this->assertEquals([Perfil::Morador], $usuario->perfisNo($this->condominio));
        $this->assertSame($usuario->id, $pessoa->fresh()->user_id);
        $this->assertFalse($usuario->isSuperAdmin());
    }

    public function test_sindico_nao_consegue_dar_perfil_de_operador_nem_super_admin(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::usuarios.form')
            ->set('name', 'Tentativa')
            ->set('email', 'tentativa@exemplo.com')
            ->set('password', 'senha-segura-1')
            ->set('perfis', ['operador', 'morador'])
            ->set('super_admin', true)
            ->call('salvar');

        $usuario = User::where('email', 'tentativa@exemplo.com')->firstOrFail();
        $this->assertEquals([Perfil::Morador], $usuario->perfisNo($this->condominio));
        $this->assertFalse($usuario->isSuperAdmin());
    }

    public function test_email_existente_apenas_ganha_acesso_sem_trocar_senha(): void
    {
        $existente = $this->usuarioCom(Perfil::Morador, $this->outro);
        $senhaAntiga = $existente->password;
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::usuarios.form')
            ->set('name', 'Nome Trocado')
            ->set('email', $existente->email)
            ->set('password', 'outra-senha-123')
            ->set('perfis', ['morador'])
            ->call('salvar')
            ->assertHasNoErrors();

        $existente->refresh();
        $this->assertSame($senhaAntiga, $existente->password);
        $this->assertNotSame('Nome Trocado', $existente->name);
        $this->assertTrue($existente->podeAcessarCondominio($this->condominio));
        $this->assertTrue($existente->podeAcessarCondominio($this->outro));
    }

    public function test_sindico_nao_troca_senha_de_usuario_de_varios_condominios(): void
    {
        $alvo = $this->usuarioCom(Perfil::Morador);
        $alvo->atribuirPerfil(Perfil::Morador, $this->outro);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::usuarios.form', ['usuario' => $alvo])
            ->set('password', 'invasao-12345')
            ->set('email', 'novo@exemplo.com')
            ->call('salvar');

        $alvo->refresh();
        $this->assertFalse(Hash::check('invasao-12345', $alvo->password));
        $this->assertNotSame('novo@exemplo.com', $alvo->email);
    }

    public function test_sindico_troca_senha_de_usuario_exclusivo_do_condominio(): void
    {
        $alvo = $this->usuarioCom(Perfil::Morador);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::usuarios.form', ['usuario' => $alvo])
            ->set('password', 'nova-senha-123')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('nova-senha-123', $alvo->fresh()->password));
    }

    public function test_sindico_preserva_perfil_de_operador_ao_editar(): void
    {
        $operador = $this->usuarioCom(Perfil::Operador);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::usuarios.form', ['usuario' => $operador])
            ->set('perfis', ['porteiro'])
            ->call('salvar');

        $perfis = array_map(fn ($p) => $p->value, $operador->perfisNo($this->condominio));
        sort($perfis);
        $this->assertSame(['operador', 'porteiro'], $perfis);
    }

    public function test_sindico_nao_abre_usuario_de_outro_condominio(): void
    {
        $alheio = $this->usuarioCom(Perfil::Morador, $this->outro);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        $this->get(route('usuarios.editar', $alheio))->assertNotFound();
    }

    public function test_lista_mostra_somente_usuarios_do_condominio(): void
    {
        $daqui = $this->usuarioCom(Perfil::Morador);
        $dali = $this->usuarioCom(Perfil::Morador, $this->outro);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        $this->get(route('usuarios.index'))->assertOk()->assertSee($daqui->email)->assertDontSee($dali->email);
    }

    public function test_remover_do_condominio_mantem_acesso_aos_outros(): void
    {
        $alvo = $this->usuarioCom(Perfil::Morador);
        $alvo->atribuirPerfil(Perfil::Morador, $this->outro);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::usuarios.index')->call('removerDoCondominio', $alvo->id);

        $this->assertFalse($alvo->podeAcessarCondominio($this->condominio));
        $this->assertTrue($alvo->podeAcessarCondominio($this->outro));
    }

    public function test_super_admin_desativa_usuario_e_redefine_2fa(): void
    {
        $alvo = $this->usuarioCom(Perfil::Operador);
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        Livewire::test('pages::usuarios.form', ['usuario' => $alvo])
            ->call('redefinirDoisFatores')
            ->set('ativo', false)
            ->call('salvar')
            ->assertHasNoErrors();

        $alvo->refresh();
        $this->assertFalse($alvo->ativo);
        $this->assertNull($alvo->two_factor_secret);
    }

    public function test_morador_nao_gerencia_usuarios(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Morador))
            ->get(route('usuarios.index'))->assertForbidden();
    }
}
