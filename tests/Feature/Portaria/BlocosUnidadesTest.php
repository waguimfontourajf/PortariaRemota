<?php

namespace Tests\Feature\Portaria;

use App\Enums\Perfil;
use App\Models\Bloco;
use App\Models\Unidade;
use Livewire\Livewire;

class BlocosUnidadesTest extends CadastrosTestCase
{
    public function test_morador_nao_acessa_cadastros(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Morador));

        $this->get(route('blocos.index'))->assertForbidden();
        $this->get(route('unidades.index'))->assertForbidden();
        $this->get(route('pessoas.index'))->assertForbidden();
    }

    public function test_sindico_cria_bloco_e_nome_repetido_e_recusado(): void
    {
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::cadastros.blocos')->call('novo')->set('nome', 'Torre A')->call('salvar')->assertHasNoErrors();
        Livewire::test('pages::cadastros.blocos')->call('novo')->set('nome', 'Torre A')->call('salvar')->assertHasErrors('nome');

        $this->assertSame(1, $this->condominio->blocos()->count());
    }

    public function test_mesmo_nome_de_bloco_pode_existir_em_outro_condominio(): void
    {
        Bloco::factory()->for($this->outro)->create(['nome' => 'Torre A']);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::cadastros.blocos')->call('novo')->set('nome', 'Torre A')->call('salvar')->assertHasNoErrors();
    }

    public function test_nao_edita_bloco_de_outro_condominio(): void
    {
        $alheio = Bloco::factory()->for($this->outro)->create();
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::cadastros.blocos')->call('editar', $alheio->id)->assertNotFound();
    }

    public function test_lista_mostra_somente_unidades_do_condominio_atual(): void
    {
        Unidade::factory()->for($this->condominio)->create(['numero' => '7101']);
        Unidade::factory()->for($this->outro)->create(['numero' => '9909']);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        $this->get(route('unidades.index'))->assertOk()->assertSee('7101')->assertDontSee('9909');
    }

    public function test_gera_unidades_em_lote_sem_duplicar(): void
    {
        $bloco = Bloco::factory()->for($this->condominio)->create(['nome' => 'A']);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        $lote = fn () => Livewire::test('pages::cadastros.unidades')
            ->call('abrirLote')
            ->set('lote_bloco_id', (string) $bloco->id)
            ->set('lote_tipo', 'apartamento')
            ->set('lote_andar_inicial', 1)
            ->set('lote_andar_final', 3)
            ->set('lote_por_andar', 4)
            ->call('gerarLote')
            ->assertHasNoErrors();

        $lote();
        $lote();

        $numeros = $bloco->unidades()->pluck('numero')->sort()->values()->all();
        $this->assertCount(12, $numeros);
        $this->assertSame(['101', '102', '103', '104'], array_slice($numeros, 0, 4));
        $this->assertContains('304', $numeros);
    }

    public function test_numero_de_unidade_repetido_no_mesmo_bloco_e_recusado(): void
    {
        $bloco = Bloco::factory()->for($this->condominio)->create();
        Unidade::factory()->for($this->condominio)->create(['bloco_id' => $bloco->id, 'numero' => '101']);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::cadastros.unidades')->call('nova')
            ->set('bloco_id', (string) $bloco->id)->set('numero', '101')
            ->call('salvar')->assertHasErrors('numero');
    }

    public function test_bloco_com_unidades_nao_e_excluido(): void
    {
        $bloco = Bloco::factory()->for($this->condominio)->create();
        Unidade::factory()->for($this->condominio)->create(['bloco_id' => $bloco->id]);
        $this->entrarComo($this->usuarioCom(Perfil::Sindico));

        Livewire::test('pages::cadastros.blocos')->call('excluir', $bloco->id);

        $this->assertModelExists($bloco);
    }
}
