<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#18181b">
    <title>{{ __('Acesso de visitante') }} — {{ $visita->condominio->nome }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-800 antialiased dark:bg-zinc-900 dark:text-zinc-100">
    <main class="mx-auto flex min-h-screen max-w-md flex-col px-5 py-8">
        <div class="flex items-center gap-3">
            <img src="/icones/icone-192.png" alt="" class="size-11 rounded-xl">
            <div>
                <div class="text-sm text-zinc-500">{{ __('Acesso de visitante') }}</div>
                <div class="font-semibold">{{ $visita->condominio->nome }}</div>
            </div>
        </div>

        <h1 class="mt-8 text-2xl font-semibold">{{ __('Olá, :nome!', ['nome' => Str::before($visita->nome, ' ')]) }}</h1>
        <p class="mt-2 text-zinc-600 dark:text-zinc-400">
            {{ __('Sua entrada foi autorizada por :quem', ['quem' => Str::before($visita->autor?->name ?? __('um morador'), ' ')]) }}{{ $visita->unidade ? ' ('.$visita->unidade->identificacao.')' : '' }}.
            {{ __('Válida até :data.', ['data' => $visita->valido_ate->timezone($visita->condominio->fuso_horario)->format('d/m \à\s H:i')]) }}
        </p>

        @if (session('resultado'))
            @php($r = session('resultado'))
            <div @class([
                'mt-6 rounded-xl p-4 text-sm font-medium',
                'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200' => $r['sucesso'],
                'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200' => ! $r['sucesso'],
            ]) role="status">{{ $r['mensagem'] }}</div>
        @endif

        @if ($bloqueio)
            <div class="mt-6 rounded-xl bg-zinc-100 p-4 text-sm dark:bg-zinc-800">{{ $bloqueio }}</div>
        @else
            <p class="mt-6 text-sm text-zinc-500">
                {{ __('Toque no botão quando estiver em frente ao portão.') }}
                {{ trans_choice('{1} Resta 1 entrada.|[2,*] Restam :count entradas.', $visita->usos_maximos - $visita->usos) }}
            </p>
            <div class="mt-4 space-y-3">
                @forelse ($pontos as $ponto)
                    <form method="POST" action="{{ route('visitante.abrir', [$token, $ponto->uuid]) }}" onsubmit="const b = this.querySelector('button'); b.disabled = true; b.querySelector('span').textContent = '{{ __('Abrindo…') }}';">
                        @csrf
                        <button type="submit"
                            class="flex h-16 w-full items-center justify-center gap-3 rounded-2xl bg-zinc-900 text-lg font-semibold text-white shadow-sm active:scale-[.99] disabled:opacity-60 dark:bg-white dark:text-zinc-900">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-6"><path d="M18 1.5c2.9 0 5.25 2.35 5.25 5.25v3.75a.75.75 0 0 1-1.5 0V6.75a3.75 3.75 0 1 0-7.5 0v3a3 3 0 0 1 3 3v6.75a3 3 0 0 1-3 3H3.75a3 3 0 0 1-3-3v-6.75a3 3 0 0 1 3-3h9v-3c0-2.9 2.35-5.25 5.25-5.25Z"/></svg>
                            <span>{{ __('ABRIR :ponto', ['ponto' => mb_strtoupper($ponto->nome)]) }}</span>
                        </button>
                    </form>
                @empty
                    <div class="rounded-xl bg-zinc-100 p-4 text-sm dark:bg-zinc-800">{{ __('Nenhum portão disponível. Use o interfone.') }}</div>
                @endforelse
            </div>
        @endif

        <p class="mt-auto pt-10 text-center text-xs text-zinc-400">{{ __('Por segurança, cada abertura é registrada. Não compartilhe este link.') }}</p>
    </main>
</body>
</html>
