<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Models\User;
use Livewire\Livewire;

class CondominiosTest extends CadastrosTestCase
{
    public function test_somente_super_admin_ve_a_lista_de_condominios(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Sindico))
            ->get(route('condominios.index'))->assertForbidden();

        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create())
            ->get(route('condominios.index'))->assertOk()->assertSee('Residencial Alfa')->assertSee('Residencial Beta');
    }

    public function test_super_admin_cadastra_condominio_com_cnpj_valido(): void
    {
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        Livewire::test('pages::condominios.form')
            ->set('nome', 'Condomínio Gama')
            ->set('cnpj', '11222333000181')
            ->set('cep', '01001000')
            ->set('uf', 'sp')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('condominios', ['nome' => 'Condomínio Gama', 'cnpj' => '11.222.333/0001-81', 'cep' => '01001-000', 'uf' => 'SP']);
    }

    public function test_cnpj_invalido_e_recusado(): void
    {
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        Livewire::test('pages::condominios.form')
            ->set('nome', 'X')
            ->set('cnpj', '11.222.333/0001-00')
            ->call('salvar')
            ->assertHasErrors('cnpj');
    }

    public function test_sindico_edita_dados_do_proprio_condominio_mas_nao_o_modo_nem_a_situacao(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::condominios.form', ['condominio' => $this->condominio])
            ->set('telefone', '11987654321')
            ->set('modo_operacao', 'local')
            ->set('ativo', false)
            ->call('salvar')
            ->assertHasNoErrors();

        $this->condominio->refresh();
        $this->assertSame('(11) 98765-4321', $this->condominio->telefone);
        $this->assertSame('direto', $this->condominio->modo_operacao->value);
        $this->assertTrue($this->condominio->ativo);
    }

    public function test_sindico_nao_edita_outro_condominio_nem_cria(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        $this->get(route('condominios.editar', $this->outro))->assertForbidden();
        $this->get(route('condominios.criar'))->assertForbidden();
    }

    public function test_morador_nao_edita_o_condominio(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Morador))
            ->get(route('condominios.editar', $this->condominio))->assertForbidden();
    }

    public function test_super_admin_desativa_condominio(): void
    {
        $this->entrarComo(User::factory()->superAdmin()->withTwoFactor()->create());

        Livewire::test('pages::condominios.index')->call('alternarAtivo', $this->outro->uuid);

        $this->assertFalse($this->outro->fresh()->ativo);
    }
}
