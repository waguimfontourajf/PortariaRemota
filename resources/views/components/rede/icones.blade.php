{{-- Ícone de cada nó do mapa, escolhido pelo campo "icone" do nó (Alpine). --}}
@php
    $icones = collect(\App\Enums\TipoDispositivo::cases())->map->icone()
        ->merge(['cloud', 'building-office-2'])->unique()->values();
@endphp
@foreach ($icones as $icone)
    <template x-if="n.icone === @js($icone)">
        <flux:icon :name="$icone" class="size-7" />
    </template>
@endforeach
