{{--
    Seletor do condomínio atual.
    Mostra o condomínio em operação e permite trocar entre os que o usuário tem acesso.
--}}
@php
    $usuario = auth()->user();
    $condominios = $usuario?->condominiosAcessiveis()->get(['id', 'uuid', 'nome']) ?? collect();
@endphp

<div {{ $attributes->class('w-full') }}>
    @if ($condominios->isEmpty())
        <flux:callout variant="secondary" icon="building-office-2" class="text-sm">
            <flux:callout.text>{{ __('Nenhum condomínio vinculado ao seu usuário.') }}</flux:callout.text>
        </flux:callout>
    @else
        <flux:dropdown position="bottom" align="start" class="w-full">
            <flux:button
                variant="outline"
                icon="building-office-2"
                icon:trailing="chevrons-up-down"
                class="w-full justify-between! truncate"
                data-test="seletor-condominio"
            >
                <span class="truncate">{{ $condominioAtual?->nome ?? __('Selecionar condomínio') }}</span>
            </flux:button>

            <flux:menu class="max-h-96 min-w-64 overflow-y-auto">
                <flux:menu.heading>{{ __('Condomínio em operação') }}</flux:menu.heading>

                @foreach ($condominios as $condominio)
                    <form method="POST" action="{{ route('condominio.selecionar', $condominio) }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            class="w-full cursor-pointer"
                            :icon="$condominio->id === $condominioAtual?->id ? 'check' : 'building-office'"
                        >
                            {{ $condominio->nome }}
                        </flux:menu.item>
                    </form>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
