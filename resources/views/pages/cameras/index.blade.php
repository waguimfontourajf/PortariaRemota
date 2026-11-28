<?php

use App\Cameras\Cameras;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Câmeras')] class extends Component {
    use OperaNoCondominioAtual;

    #[Url(except: 2)]
    public int $colunas = 2;

    public function mount(): void
    {
        abort_if($this->cameras->isEmpty() && ! auth()->user()->can(\App\Enums\Permissao::CamerasVer->value), 403);
    }

    #[Computed]
    public function cameras()
    {
        return $this->condominio()->dispositivos()
            ->where('tipo', 'camera')->where('ativo', true)->orderBy('nome')->get()
            ->filter(fn ($c) => Cameras::podeVer(auth()->user(), $c))
            ->values();
    }
}; ?>

<section class="w-full">
    <x-cabecalho-pagina :titulo="__('Câmeras')" :subtitulo="$this->condominio()->nome.' — '.__('ao vivo')">
        <x-slot:acoes>
            <flux:radio.group wire:model.live="colunas" variant="segmented" class="max-sm:hidden">
                <flux:radio :value="1" icon="stop" />
                <flux:radio :value="2" icon="squares-2x2" />
                <flux:radio :value="3" icon="squares-plus" />
            </flux:radio.group>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    @if ($this->cameras->isEmpty())
        <flux:callout icon="video-camera" variant="secondary">
            <flux:callout.text>
                {{ __('Nenhuma câmera cadastrada.') }}
                @can(\App\Enums\Permissao::DispositivosGerenciar->value)
                    <flux:callout.link :href="route('dispositivos.criar', ['tipo' => 'camera'])" wire:navigate>{{ __('Cadastrar câmera') }}</flux:callout.link>
                @endcan
            </flux:callout.text>
        </flux:callout>
    @else
        <div @class([
            'grid gap-3',
            'sm:grid-cols-1 max-w-5xl' => $colunas === 1,
            'sm:grid-cols-2' => $colunas === 2,
            'sm:grid-cols-2 xl:grid-cols-3' => $colunas === 3,
        ])>
            @foreach ($this->cameras as $camera)
                <x-camera-ao-vivo :camera="$camera" wire:key="camera-{{ $camera->id }}" wire:ignore />
            @endforeach
        </div>
        <flux:text class="mt-3 text-xs">{{ __('Toque na imagem para tela cheia. A imagem é atualizada cerca de duas vezes por segundo.') }}</flux:text>
    @endif
</section>
