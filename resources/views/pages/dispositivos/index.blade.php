<?php

use App\Enums\Permissao;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Dispositivo;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dispositivos')] class extends Component {
    use OperaNoCondominioAtual;

    public function mount(): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
    }

    #[Computed]
    public function dispositivos()
    {
        return $this->condominio()->dispositivos()->withCount('pontosAcesso')->orderBy('nome')->get();
    }

    public function testar(int $id): void
    {
        $dispositivo = $this->buscar($id);
        $resultado = $dispositivo->driverDeRele()->testar($dispositivo);
        $dispositivo->registrarContato($resultado->sucesso, $resultado->sucesso ? null : $resultado->mensagem);

        Flux::toast(
            variant: $resultado->sucesso ? 'success' : 'danger',
            heading: $dispositivo->nome,
            text: $resultado->mensagem.' ('.$resultado->duracaoMs.' ms)',
        );
        unset($this->dispositivos);
    }

    public function excluir(int $id): void
    {
        $dispositivo = $this->buscar($id);

        if ($dispositivo->pontosAcesso()->exists()) {
            Flux::toast(variant: 'danger', text: __('Há pontos de acesso usando este dispositivo. Troque o dispositivo deles antes de excluir.'));

            return;
        }

        $dispositivo->delete();
        Flux::toast(variant: 'success', text: __('Dispositivo excluído.'));
        unset($this->dispositivos);
    }

    private function buscar(int $id): Dispositivo
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);

        return $this->condominio()->dispositivos()->findOrFail($id);
    }
}; ?>

<section class="w-full">
    <x-cabecalho-pagina :titulo="__('Dispositivos')" :subtitulo="__('Relés e demais equipamentos instalados no condomínio.')">
        <x-slot:acoes>
            <flux:button variant="primary" icon="plus" :href="route('dispositivos.criar')" wire:navigate>{{ __('Novo dispositivo') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Nome') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Modelo / driver') }}</flux:table.column>
            <flux:table.column class="max-lg:hidden">{{ __('Endereço') }}</flux:table.column>
            <flux:table.column>{{ __('Comunicação') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden" align="end">{{ __('Pontos') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->dispositivos as $d)
                <flux:table.row :key="$d->id">
                    <flux:table.cell>
                        <flux:link variant="subtle" class="font-medium text-zinc-800! dark:text-white!" :href="route('dispositivos.editar', $d)" wire:navigate>{{ $d->nome }}</flux:link>
                        @unless ($d->ativo)
                            <flux:badge size="sm" color="zinc" class="ms-1">{{ __('Inativo') }}</flux:badge>
                        @endunless
                    </flux:table.cell>
                    <flux:table.cell class="max-md:hidden">{{ $d->nomeDoDriver() }}</flux:table.cell>
                    <flux:table.cell class="max-lg:hidden font-mono text-xs">{{ $d->host ? $d->host.($d->porta ? ':'.$d->porta : '') : '—' }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($d->online === null)
                            <flux:badge size="sm" color="zinc">{{ __('Não testado') }}</flux:badge>
                        @elseif ($d->online)
                            <flux:tooltip :content="__('Último contato: :data', ['data' => $d->ultimo_contato_em?->format('d/m/Y H:i:s')])">
                                <flux:badge size="sm" color="green" icon="signal">{{ __('Online') }}</flux:badge>
                            </flux:tooltip>
                        @else
                            <flux:tooltip :content="$d->ultimo_erro">
                                <flux:badge size="sm" color="red" icon="signal-slash">{{ __('Sem resposta') }}</flux:badge>
                            </flux:tooltip>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="max-sm:hidden" align="end">{{ $d->pontos_acesso_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="signal" inset="top bottom" wire:click="testar({{ $d->id }})" :tooltip="__('Testar comunicação')" />
                        <flux:dropdown position="bottom" align="end">
                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" :aria-label="__('Ações')" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" :href="route('dispositivos.editar', $d)" wire:navigate>{{ __('Editar') }}</flux:menu.item>
                                <flux:menu.separator />
                                <flux:menu.item variant="danger" icon="trash" wire:click="excluir({{ $d->id }})" wire:confirm="{{ __('Excluir o dispositivo :nome?', ['nome' => $d->nome]) }}">{{ __('Excluir') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="py-8 text-center">{{ __('Nenhum dispositivo cadastrado.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
