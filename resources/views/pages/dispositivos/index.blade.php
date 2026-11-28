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

        if (! $dispositivo->temDriver()) {
            // Equipamento de rede: roda o mesmo teste do monitoramento.
            app(\App\Monitoramento\Monitor::class)->executar(somente: collect([$dispositivo]));
            $dispositivo->refresh();
            $ok = $dispositivo->ultimo_erro === null;
            Flux::toast(
                variant: $ok ? 'success' : 'danger',
                heading: $dispositivo->nome,
                text: $ok ? __('Respondeu em :ms ms.', ['ms' => $dispositivo->latencia_ms ?? '<1']) : $dispositivo->ultimo_erro,
            );
            unset($this->dispositivos);

            return;
        }

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
            <flux:button icon="share" :href="route('rede.mapa')" wire:navigate>{{ __('Mapa da rede') }}</flux:button>
            <flux:button variant="primary" icon="plus" :href="route('dispositivos.criar')" wire:navigate>{{ __('Novo dispositivo') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Nome') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Tipo / driver') }}</flux:table.column>
            <flux:table.column class="max-lg:hidden">{{ __('Endereço') }}</flux:table.column>
            <flux:table.column>{{ __('Comunicação') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden" align="end">{{ __('Pontos') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->dispositivos as $d)
                <flux:table.row :key="$d->id">
                    <flux:table.cell>
                        <flux:icon :name="$d->tipo->icone()" variant="mini" class="me-1 inline text-zinc-400" />
                        <flux:link variant="subtle" class="font-medium text-zinc-800! dark:text-white!" :href="route('dispositivos.editar', $d)" wire:navigate>{{ $d->nome }}</flux:link>
                        @unless ($d->ativo)
                            <flux:badge size="sm" color="zinc" class="ms-1">{{ __('Inativo') }}</flux:badge>
                        @endunless
                    </flux:table.cell>
                    <flux:table.cell class="max-md:hidden">{{ $d->temDriver() ? $d->nomeDoDriver() : $d->tipo->label() }}</flux:table.cell>
                    <flux:table.cell class="max-lg:hidden font-mono text-xs">{{ $d->host ? $d->host.($d->porta ? ':'.$d->porta : '') : '—' }}</flux:table.cell>
                    <flux:table.cell>
                        @php($status = $d->statusEfetivo())
                        @if ($status === \App\Enums\StatusMonitoramento::NaoMonitorado || $status === \App\Enums\StatusMonitoramento::Desconhecido)
                            @if ($d->online === null)
                                <flux:badge size="sm" color="zinc">{{ $status->label() }}</flux:badge>
                            @else
                                <flux:badge size="sm" :color="$d->online ? 'green' : 'red'">{{ $d->online ? __('Respondeu no último teste') : __('Sem resposta') }}</flux:badge>
                            @endif
                        @else
                            <flux:tooltip :content="$status === \App\Enums\StatusMonitoramento::Offline ? $d->ultimo_erro : __('Verificado :t', ['t' => $d->verificado_em?->diffForHumans()])">
                                <flux:badge size="sm" :color="$status->cor()">
                                    {{ $status->label() }}{{ $d->latencia_ms !== null && $status !== \App\Enums\StatusMonitoramento::Offline ? ' · '.$d->latencia_ms.' ms' : '' }}
                                </flux:badge>
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
