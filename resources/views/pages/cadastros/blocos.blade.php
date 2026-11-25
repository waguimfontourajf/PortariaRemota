<?php

use App\Enums\Permissao;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Bloco;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Blocos')] class extends Component {
    use OperaNoCondominioAtual;

    #[Locked]
    public ?int $blocoId = null;

    public string $nome = '';
    public int $ordem = 0;

    public function mount(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
    }

    #[Computed]
    public function blocos()
    {
        return $this->condominio()->blocos()->withCount('unidades')->orderBy('ordem')->orderBy('nome')->get();
    }

    public function novo(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
        $this->reset('blocoId', 'nome');
        $this->ordem = ($this->blocos->max('ordem') ?? 0) + 1;
        $this->resetValidation();
        Flux::modal('bloco')->show();
    }

    public function editar(int $id): void
    {
        $bloco = $this->buscar($id);
        $this->blocoId = $bloco->id;
        $this->nome = $bloco->nome;
        $this->ordem = $bloco->ordem;
        $this->resetValidation();
        Flux::modal('bloco')->show();
    }

    public function salvar(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
        $this->nome = trim($this->nome);

        $dados = $this->validate([
            'nome' => [
                'required', 'string', 'max:60',
                Rule::unique('blocos')->where('condominio_id', $this->condominio()->id)->ignore($this->blocoId),
            ],
            'ordem' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        if ($this->blocoId) {
            $this->buscar($this->blocoId)->update($dados);
        } else {
            $this->condominio()->blocos()->create($dados);
        }

        Flux::modal('bloco')->close();
        Flux::toast(variant: 'success', text: __('Bloco salvo.'));
        unset($this->blocos);
    }

    public function excluir(int $id): void
    {
        $bloco = $this->buscar($id);

        if ($bloco->unidades()->exists()) {
            Flux::toast(variant: 'danger', text: __('Este bloco tem unidades. Remova ou mova as unidades antes de excluir.'));

            return;
        }

        $bloco->delete();
        Flux::toast(variant: 'success', text: __('Bloco excluído.'));
        unset($this->blocos);
    }

    private function buscar(int $id): Bloco
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);

        return $this->condominio()->blocos()->findOrFail($id);
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-cabecalho-pagina :titulo="__('Blocos e torres')" :subtitulo="__('Organize as unidades do condomínio por bloco ou torre. Condomínios de casas podem não ter blocos.')">
        <x-slot:acoes>
            <flux:button variant="primary" icon="plus" wire:click="novo">{{ __('Novo bloco') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Nome') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Unidades') }}</flux:table.column>
            <flux:table.column align="end" class="max-sm:hidden">{{ __('Ordem') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->blocos as $bloco)
                <flux:table.row :key="$bloco->id">
                    <flux:table.cell variant="strong">{{ $bloco->nome }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:link :href="route('unidades.index', ['bloco' => $bloco->id])" wire:navigate>{{ $bloco->unidades_count }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell align="end" class="max-sm:hidden">{{ $bloco->ordem }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" inset="top bottom" wire:click="editar({{ $bloco->id }})" :aria-label="__('Editar')" />
                        <flux:button size="sm" variant="ghost" icon="trash" inset="top bottom" wire:click="excluir({{ $bloco->id }})" wire:confirm="{{ __('Excluir o bloco :nome?', ['nome' => $bloco->nome]) }}" :aria-label="__('Excluir')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="py-8 text-center">{{ __('Nenhum bloco cadastrado.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="bloco" class="w-full max-w-md">
        <form wire:submit="salvar" class="space-y-6">
            <flux:heading size="lg">{{ $blocoId ? __('Editar bloco') : __('Novo bloco') }}</flux:heading>
            <flux:input wire:model="nome" :label="__('Nome')" :placeholder="__('Ex.: Bloco A, Torre 1')" required autofocus />
            <flux:input wire:model="ordem" type="number" min="0" :label="__('Ordem de exibição')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Salvar') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
