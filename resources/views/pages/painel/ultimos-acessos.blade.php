<?php

use App\Models\Condominio;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Últimos acessos" do painel: atualiza sozinho quando chega acesso novo.
 */
new class extends Component {
    #[Locked]
    public int $condominioId;

    public function mount(Condominio $condominio): void
    {
        $this->condominioId = $condominio->id;
    }

    public function render()
    {
        $condominio = Condominio::findOrFail($this->condominioId);
        abort_unless(auth()->user()->podeAcessarCondominio($condominio), 403);

        return $this->view([
            'condominio' => $condominio,
            'eventos' => $condominio->eventosAcesso()->latest('id')->limit(8)->get(),
        ]);
    }
}; ?>

<div x-on:tempo-real-acessos.window="$wire.$refresh()">
    <flux:card class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <flux:heading>{{ __('Últimos acessos') }}</flux:heading>
            <flux:link :href="route('acessos.historico')" wire:navigate class="text-sm">{{ __('Ver histórico') }}</flux:link>
        </div>
        @forelse ($eventos as $e)
            <div wire:key="ev-{{ $e->id }}" @class([
                'flex items-center gap-3 rounded-md px-1 text-sm transition-colors duration-1000',
                'bg-amber-100 dark:bg-amber-900/40' => $e->created_at->gt(now()->subSeconds(10)),
            ])>
                <flux:badge size="sm" :color="$e->resultado->cor()">{{ $e->resultado->label() }}</flux:badge>
                <span class="font-medium text-zinc-800 dark:text-white">{{ $e->ponto_nome }}</span>
                <span class="hidden text-zinc-500 sm:inline">{{ $e->acao->label() }}</span>
                <span class="truncate text-zinc-500">{{ $e->usuario_nome ?? __('Sistema') }}</span>
                <span class="ms-auto whitespace-nowrap text-zinc-500">{{ $e->created_at->timezone($condominio->fuso_horario)->format('d/m H:i:s') }}</span>
            </div>
        @empty
            <flux:text>{{ __('Nenhum acesso registrado ainda.') }}</flux:text>
        @endforelse
    </flux:card>
</div>
