<?php

use App\Enums\Permissao;
use App\Enums\TipoPessoa;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Pessoa;
use App\Support\FotoDePessoa;
use App\Support\SincronizacaoFacial;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Pessoas')] class extends Component {
    use OperaNoCondominioAtual, WithPagination;

    #[Url(except: '')]
    public string $busca = '';

    #[Url(except: '')]
    public string $tipo = '';

    #[Url(except: 'ativos')]
    public string $situacao = 'ativos';

    public function mount(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
    }

    public function updating(string $campo): void
    {
        if (in_array($campo, ['busca', 'tipo', 'situacao'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function pessoas()
    {
        return Pessoa::query()
            ->doCondominio($this->condominio())
            ->with(['unidades.bloco', 'usuario:id,ativo'])
            ->busca($this->busca)
            ->when($this->tipo !== '', fn ($q) => $q->where('tipo', $this->tipo))
            ->when($this->situacao === 'ativos', fn ($q) => $q->where('ativo', true))
            ->when($this->situacao === 'inativos', fn ($q) => $q->where('ativo', false))
            ->orderBy('nome')
            ->paginate(30);
    }

    public function alternarAtivo(int $id): void
    {
        $pessoa = $this->buscar($id);
        $pessoa->update(['ativo' => ! $pessoa->ativo]);
        Flux::toast(variant: 'success', text: $pessoa->ativo ? __('Pessoa reativada.') : __('Pessoa desativada.'));
        $this->avisarLeitoras(app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa));
    }

    public function excluir(int $id): void
    {
        $pessoa = $this->buscar($id);
        $pessoa->delete();
        Flux::toast(variant: 'success', text: __('Pessoa excluída.'));

        // Sai das leitoras faciais e a foto (dado biométrico) é apagada.
        $this->avisarLeitoras(app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa));
        app(FotoDePessoa::class)->remover($pessoa);
        $pessoa->forceFill(['biometria_autorizada_em' => null, 'biometria_autorizada_por' => null])->save();
    }

    /**
     * @param  list<array{leitora: string, sucesso: bool, mensagem: string}>  $resultados
     */
    private function avisarLeitoras(array $resultados): void
    {
        $erros = array_filter($resultados, fn ($r) => ! $r['sucesso']);

        if ($erros !== []) {
            Flux::toast(variant: 'warning', duration: 15000, heading: __('A leitora facial não foi atualizada'),
                text: collect($erros)->map(fn ($r) => "{$r['leitora']}: {$r['mensagem']}")->join(' | '));
        }
    }

    private function buscar(int $id): Pessoa
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);

        return Pessoa::query()->doCondominio($this->condominio())->findOrFail($id);
    }
}; ?>

<section class="w-full">
    <x-cabecalho-pagina :titulo="__('Pessoas')" :subtitulo="__('Moradores, funcionários e prestadores do condomínio.')">
        <x-slot:acoes>
            <flux:button variant="primary" icon="user-plus" :href="route('pessoas.criar')" wire:navigate>{{ __('Nova pessoa') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.400ms="busca" icon="magnifying-glass" :placeholder="__('Nome, CPF, telefone ou e-mail')" class="sm:max-w-sm" />
        <flux:select wire:model.live="tipo" class="sm:max-w-56">
            <flux:select.option value="">{{ __('Todos os tipos') }}</flux:select.option>
            @foreach (TipoPessoa::cases() as $t)
                <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="situacao" class="sm:max-w-40">
            <flux:select.option value="ativos">{{ __('Ativos') }}</flux:select.option>
            <flux:select.option value="inativos">{{ __('Inativos') }}</flux:select.option>
            <flux:select.option value="todos">{{ __('Todos') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->pessoas">
        <flux:table.columns>
            <flux:table.column>{{ __('Nome') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Unidades') }}</flux:table.column>
            <flux:table.column class="max-lg:hidden">{{ __('Telefone') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden">{{ __('Tipo') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->pessoas as $pessoa)
                <flux:table.row :key="$pessoa->id">
                    <flux:table.cell>
                        <div class="flex items-center gap-2">
                            <flux:link variant="subtle" class="font-medium text-zinc-800! dark:text-white!" :href="route('pessoas.editar', $pessoa)" wire:navigate>{{ $pessoa->nome }}</flux:link>
                            @if ($pessoa->usuario)
                                <flux:tooltip :content="__('Tem acesso ao sistema')"><flux:icon.key variant="micro" class="text-zinc-400" /></flux:tooltip>
                            @endif
                            @unless ($pessoa->ativo)
                                <flux:badge size="sm" color="zinc">{{ __('Inativa') }}</flux:badge>
                            @endunless
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="max-md:hidden max-w-64 truncate">
                        {{ $pessoa->unidades->map->identificacao->join(', ') ?: '—' }}
                    </flux:table.cell>
                    <flux:table.cell class="max-lg:hidden">{{ $pessoa->telefone ?? '—' }}</flux:table.cell>
                    <flux:table.cell class="max-sm:hidden">{{ $pessoa->tipo->label() }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown position="bottom" align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Ações')" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" :href="route('pessoas.editar', $pessoa)" wire:navigate>{{ __('Editar') }}</flux:menu.item>
                                <flux:menu.item :icon="$pessoa->ativo ? 'pause-circle' : 'play-circle'" wire:click="alternarAtivo({{ $pessoa->id }})">
                                    {{ $pessoa->ativo ? __('Desativar') : __('Reativar') }}
                                </flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item variant="danger" icon="trash" wire:click="excluir({{ $pessoa->id }})" wire:confirm="{{ __('Excluir :nome? O histórico de acessos é mantido.', ['nome' => $pessoa->nome]) }}">{{ __('Excluir') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-8 text-center">{{ __('Nenhuma pessoa encontrada.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
