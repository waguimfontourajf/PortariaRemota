<?php

use App\Models\Condominio;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Condomínios')] class extends Component {
    use WithPagination;

    #[Url(as: 'busca', except: '')]
    public string $busca = '';

    #[Url(except: 'ativos')]
    public string $situacao = 'ativos';

    public function mount(): void
    {
        $this->exigirSuperAdmin();
    }

    public function updating(string $campo): void
    {
        if (in_array($campo, ['busca', 'situacao'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function condominios()
    {
        return Condominio::query()
            ->withCount(['unidades', 'usuarios'])
            ->when($this->busca, fn ($q) => $q->where(fn ($q) => $q
                ->where('nome', 'like', "%{$this->busca}%")
                ->orWhere('cidade', 'like', "%{$this->busca}%")
                ->orWhere('cnpj', 'like', "%{$this->busca}%")))
            ->when($this->situacao === 'ativos', fn ($q) => $q->where('ativo', true))
            ->when($this->situacao === 'inativos', fn ($q) => $q->where('ativo', false))
            ->orderBy('nome')
            ->paginate(20);
    }

    public function alternarAtivo(string $uuid): void
    {
        $this->exigirSuperAdmin();

        $condominio = Condominio::where('uuid', $uuid)->firstOrFail();
        $condominio->update(['ativo' => ! $condominio->ativo]);

        Flux::toast(
            variant: 'success',
            text: $condominio->ativo ? __('Condomínio reativado.') : __('Condomínio desativado.'),
        );
    }

    private function exigirSuperAdmin(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }
}; ?>

<section class="w-full">
    <x-cabecalho-pagina :titulo="__('Condomínios')" :subtitulo="__('Todos os condomínios atendidos pela plataforma.')">
        <x-slot:acoes>
            <flux:button variant="primary" icon="plus" :href="route('condominios.criar')" wire:navigate>
                {{ __('Novo condomínio') }}
            </flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.400ms="busca" icon="magnifying-glass" :placeholder="__('Buscar por nome, cidade ou CNPJ')" class="sm:max-w-sm" />
        <flux:select wire:model.live="situacao" class="sm:max-w-44">
            <flux:select.option value="ativos">{{ __('Ativos') }}</flux:select.option>
            <flux:select.option value="inativos">{{ __('Inativos') }}</flux:select.option>
            <flux:select.option value="todos">{{ __('Todos') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->condominios">
        <flux:table.columns>
            <flux:table.column>{{ __('Nome') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Cidade') }}</flux:table.column>
            <flux:table.column class="max-lg:hidden">{{ __('Modo') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden" align="end">{{ __('Unidades') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden" align="end">{{ __('Usuários') }}</flux:table.column>
            <flux:table.column>{{ __('Situação') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->condominios as $condominio)
                <flux:table.row :key="$condominio->uuid">
                    <flux:table.cell variant="strong" class="max-w-64 truncate">{{ $condominio->nome }}</flux:table.cell>
                    <flux:table.cell class="max-md:hidden">{{ collect([$condominio->cidade, $condominio->uf])->filter()->join(' / ') ?: '—' }}</flux:table.cell>
                    <flux:table.cell class="max-lg:hidden">{{ $condominio->modo_operacao->label() }}</flux:table.cell>
                    <flux:table.cell class="max-sm:hidden" align="end">{{ $condominio->unidades_count }}</flux:table.cell>
                    <flux:table.cell class="max-sm:hidden" align="end">{{ $condominio->usuarios_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$condominio->ativo ? 'green' : 'zinc'" inset="top bottom">
                            {{ $condominio->ativo ? __('Ativo') : __('Inativo') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown position="bottom" align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Ações')" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" :href="route('condominios.editar', $condominio)" wire:navigate>{{ __('Editar') }}</flux:menu.item>
                                <flux:menu.item
                                    :icon="$condominio->ativo ? 'pause-circle' : 'play-circle'"
                                    wire:click="alternarAtivo('{{ $condominio->uuid }}')"
                                    :wire:confirm="$condominio->ativo ? __('Desativar este condomínio? Os usuários dele perdem o acesso até ser reativado.') : null"
                                >
                                    {{ $condominio->ativo ? __('Desativar') : __('Reativar') }}
                                </flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="py-8 text-center">{{ __('Nenhum condomínio encontrado.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
