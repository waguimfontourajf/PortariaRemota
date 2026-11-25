@props(['titulo', 'subtitulo' => null])

<div {{ $attributes->class('mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between') }}>
    <div class="min-w-0">
        <flux:heading size="xl" level="1">{{ $titulo }}</flux:heading>
        @if ($subtitulo)
            <flux:text class="mt-1">{{ $subtitulo }}</flux:text>
        @endif
    </div>

    @isset($acoes)
        <div class="flex shrink-0 flex-wrap gap-2">{{ $acoes }}</div>
    @endisset
</div>
