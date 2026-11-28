@props(['camera', 'compacta' => false])

{{-- Imagem ao vivo de uma câmera (por quadros). Toque para tela cheia. --}}
<div x-data="cameraAoVivo(@js(route('cameras.imagem', $camera)))"
    {{ $attributes->class('group relative aspect-video overflow-hidden rounded-lg bg-zinc-900') }}>
    <img x-ref="imagem" alt="{{ $camera->nome }}" class="size-full object-contain" x-on:click="telaCheia()">

    <div x-show="carregando" class="absolute inset-0 flex items-center justify-center text-zinc-400">
        <flux:icon.loading class="size-6" />
    </div>
    <div x-show="erro" x-cloak class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-zinc-900/85 p-3 text-center text-xs text-zinc-200">
        <flux:icon.video-camera-slash class="size-6 text-zinc-400" />
        <span x-text="erro"></span>
    </div>

    <div class="pointer-events-none absolute inset-x-0 top-0 flex items-center justify-between bg-gradient-to-b from-black/60 to-transparent px-2 py-1 text-[11px] text-white">
        <span class="flex items-center gap-1.5 truncate">
            <span class="size-1.5 rounded-full" :class="erro ? 'bg-zinc-400' : 'bg-red-500 animate-pulse'"></span>
            {{ $camera->nome }}
        </span>
        @unless ($compacta)
            <button type="button" class="pointer-events-auto opacity-80 hover:opacity-100" x-on:click.stop="telaCheia()" aria-label="{{ __('Tela cheia') }}">
                <flux:icon.arrows-pointing-out variant="micro" />
            </button>
        @endunless
    </div>
</div>
