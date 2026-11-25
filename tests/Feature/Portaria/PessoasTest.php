<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Models\Pessoa;
use App\Models\Unidade;
use Livewire\Livewire;

class PessoasTest extends CadastrosTestCase
{
    public function test_cadastra_pessoa_com_vinculo_em_unidade(): void
    {
        $unidade = Unidade::factory()->for($this->condominio)->create();
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::cadastros.pessoas.form')
            ->set('nome', 'Ana Souza')
            ->set('cpf', '52998224725')
            ->set('telefone', '11912345678')
            ->set('vinculos', [['unidade_id' => (string) $unidade->id, 'vinculo' => 'inquilino', 'responsavel' => true]])
            ->call('salvar')
            ->assertHasNoErrors()
            ->assertRedirect(route('pessoas.index'));

        $pessoa = Pessoa::where('nome', 'Ana Souza')->firstOrFail();
        $this->assertSame($this->condominio->id, $pessoa->condominio_id);
        $this->assertSame('529.982.247-25', $pessoa->cpf);
        $this->assertSame('(11) 91234-5678', $pessoa->telefone);
        $this->assertSame('inquilino', $pessoa->unidades->first()->pivot->vinculo);
    }

    public function test_cpf_invalido_e_recusado(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::cadastros.pessoas.form')
            ->set('nome', 'Fulano')->set('cpf', '111.111.111-11')
            ->call('salvar')->assertHasErrors('cpf');
    }

    public function test_nao_vincula_unidade_de_outro_condominio(): void
    {
        $alheia = Unidade::factory()->for($this->outro)->create();
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::cadastros.pessoas.form')
            ->set('nome', 'Fulano')
            ->set('vinculos', [['unidade_id' => (string) $alheia->id, 'vinculo' => 'proprietario', 'responsavel' => false]])
            ->call('salvar')
            ->assertHasErrors('vinculos.0.unidade_id');
    }

    public function test_nao_abre_pessoa_de_outro_condominio(): void
    {
        $alheia = Pessoa::factory()->for($this->outro)->create();
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        $this->get(route('pessoas.editar', $alheia))->assertNotFound();
    }

    public function test_busca_por_cpf_sem_pontuacao(): void
    {
        Pessoa::factory()->for($this->condominio)->create(['nome' => 'Encontrada', 'cpf' => '529.982.247-25']);
        Pessoa::factory()->for($this->condominio)->create(['nome' => 'Outra Pessoa']);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::cadastros.pessoas.index')
            ->set('busca', '52998224725')
            ->assertSee('Encontrada')
            ->assertDontSee('Outra Pessoa');
    }
}
