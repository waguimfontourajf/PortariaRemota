<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="pb-24 lg:pb-8">
        {{ $slot }}
    </flux:main>

    <x-barra-inferior />

    {{-- Central de atendimento: telefone no navegador e pop-up quando o interfone toca. --}}
    @auth
        @if (\App\Central\Chamadas::condominiosQueAtende(auth()->user())->isNotEmpty())
            <div data-softphone="{{ route('central.softphone') }}" hidden></div>
            @persist('central-popup')
                <livewire:pages::central.popup />
            @endpersist
        @endif
    @endauth
</x-layouts::app.sidebar>
