{{-- Aviso: a verificação automática (agendador do Windows) não está rodando. --}}
<template x-if="monitor && monitor.parado">
    <div class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200" data-test="aviso-agendador">
        <div class="font-semibold">{{ __('A verificação automática dos equipamentos não está rodando') }}</div>
        <div class="mt-1" x-text="monitor.segundos === null ? @js(__('Ela nunca rodou neste servidor.')) : @js(__('Última verificação automática há')) + ' ' + Math.round(monitor.segundos / 60) + ' min.'"></div>
        <div class="mt-1">{{ __('Por isso o mapa só muda quando alguém clica em "Testar agora". No servidor, abra o Terminal do Laragon na pasta do sistema e rode:') }}
            <code class="rounded bg-amber-100 px-1 font-mono dark:bg-amber-900/60">implantacao\instalar-agendador.bat</code>
        </div>
    </div>
</template>
{{-- Área do mapa: grade, linhas (SVG) e nós arrastáveis. Usa o Alpine "mapaDeRede". --}}
<div class="h-[70vh] min-h-[420px] overflow-auto rounded-xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/60"
    x-on:pointermove.window="arrastar($event)" x-on:pointerup.window="soltar()">
    <div class="relative" :style="`width:${largura}px;height:${altura}px;background-image:linear-gradient(to right, rgb(161 161 170 / .18) 1px, transparent 1px),linear-gradient(to bottom, rgb(161 161 170 / .18) 1px, transparent 1px);background-size:20px 20px`">

        {{-- Uma camada SVG por ligação: o Alpine não repete elementos dentro de um <svg>. --}}
        <template x-for="l in linhas()" :key="l.id">
            <svg class="pointer-events-none absolute inset-0 overflow-visible" :width="largura" :height="altura">
                {{-- linha larga e invisível para facilitar o clique --}}
                <line :x1="l.x1" :y1="l.y1" :x2="l.x2" :y2="l.y2" stroke="transparent" stroke-width="14" class="pointer-events-auto cursor-pointer" x-on:click="editarLigacao(l)"></line>
                <line :x1="l.x1" :y1="l.y1" :x2="l.x2" :y2="l.y2" :stroke="l.cor" :stroke-width="l.espessura" :stroke-dasharray="l.traco" stroke-linecap="round"></line>
                <rect x-show="l.descricao" :x="l.mx - ((l.descricao ?? '').length * 3.3) - 6" :y="l.my - 9" :width="(l.descricao ?? '').length * 6.6 + 12" height="18" rx="4" fill="white" stroke="#d4d4d8"></rect>
                <text x-show="l.descricao" :x="l.mx" :y="l.my + 4" text-anchor="middle" font-size="11" fill="#52525b" x-text="l.descricao ?? ''"></text>
            </svg>
        </template>

        <template x-for="n in nos" :key="n.id">
            <div class="absolute flex cursor-grab touch-none flex-col items-center justify-center rounded-xl border-2 px-2 py-1.5 text-center shadow-sm transition-shadow select-none hover:shadow-md active:cursor-grabbing"
                :class="classesDoNo(n)"
                :style="`left:${n.x}px;top:${n.y}px;width:${no_w}px;height:${no_h}px`"
                x-on:pointerdown.prevent="pegar($event, n)">
                <div class="text-zinc-700 dark:text-zinc-200">
                    @include('components.rede.icones')
                </div>
                <div class="w-full truncate text-xs font-semibold text-zinc-800 dark:text-white" x-text="n.nome"></div>
                <div class="w-full truncate font-mono text-[10px] text-zinc-500" x-text="n.subtitulo ?? n.host ?? ''"></div>
                <div class="text-[10px] font-medium"
                    :class="{ 'text-green-700 dark:text-green-400': n.status === 'online', 'text-amber-700 dark:text-amber-400': n.status === 'lento' || n.status === 'parcial', 'text-red-700 dark:text-red-400': n.status === 'offline', 'text-zinc-500': ! ['online','lento','offline','parcial'].includes(n.status) }"
                    x-text="n.rodape ?? (n.status === 'offline' ? 'OFFLINE' : (n.latencia !== null && n.latencia !== undefined ? n.latencia + ' ms' : n.status_nome))"></div>
            </div>
        </template>

        <div x-show="carregado && nos.length === 0" class="absolute top-10 left-10 text-sm text-zinc-500">
            {{ __('Nenhum equipamento ainda. Use "Adicionar" para colocar o primeiro.') }}
        </div>
    </div>
</div>
