{{--
    Convite para instalar o app no celular (aparece só no celular, fora do app
    instalado, e pode ser dispensado). Android: botão "Instalar". iPhone: instruções.
--}}
<div x-data="{
        mostrar: false,
        ios: /iphone|ipad|ipod/i.test(navigator.userAgent),
        podeInstalar: !! window.__convitePwa,
        init() {
            const instalado = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
            let dispensado = false;
            try { dispensado = localStorage.getItem('pwa-dispensado') === '1'; } catch (e) {}
            const celular = window.matchMedia('(max-width: 1023px)').matches;
            this.mostrar = celular && ! instalado && ! dispensado && (this.ios || this.podeInstalar);
            window.addEventListener('pwa-instalavel', () => { this.podeInstalar = true; this.mostrar = celular && ! instalado && ! dispensado; });
        },
        async instalar() {
            const convite = window.__convitePwa;
            if (! convite) return;
            convite.prompt();
            await convite.userChoice;
            window.__convitePwa = null;
            this.mostrar = false;
        },
        dispensar() {
            this.mostrar = false;
            try { localStorage.setItem('pwa-dispensado', '1'); } catch (e) {}
        },
    }"
    x-show="mostrar" x-cloak
    {{ $attributes->class('mb-4 flex items-start gap-3 rounded-xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900') }}>
    <img src="/icones/icone-192.png" alt="" class="size-11 rounded-lg">
    <div class="min-w-0 flex-1 text-sm">
        <div class="font-semibold text-zinc-800 dark:text-white">{{ __('Instale o app no seu celular') }}</div>
        <template x-if="! ios">
            <div class="text-zinc-500">{{ __('Abra o portão com um toque, direto da tela inicial.') }}</div>
        </template>
        <template x-if="ios">
            <div class="text-zinc-500">{!! __('No Safari, toque em <b>Compartilhar</b> e depois em <b>Adicionar à Tela de Início</b>.') !!}</div>
        </template>
        <div class="mt-2 flex gap-2">
            <template x-if="! ios">
                <flux:button size="sm" variant="primary" icon="arrow-down-tray" x-on:click="instalar()">{{ __('Instalar') }}</flux:button>
            </template>
            <flux:button size="sm" variant="ghost" x-on:click="dispensar()">{{ __('Agora não') }}</flux:button>
        </div>
    </div>
</div>
