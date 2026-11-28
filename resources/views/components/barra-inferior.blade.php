{{--
    Navegação estilo aplicativo, só no celular (some no computador).
    Os itens aparecem conforme as permissões no condomínio atual.
--}}
@php
    $usuario = auth()->user();
    $itens = collect([
        ['rota' => 'acessos.abrir', 'ativo' => 'acessos.abrir', 'icone' => 'lock-open', 'nome' => __('Abrir'), 'pode' => $condominioAtual && $usuario->can(\App\Enums\Permissao::AcessosAbrir->value)],
        ['rota' => 'visitas.index', 'ativo' => 'visitas.*', 'icone' => 'qr-code', 'nome' => __('Visitantes'), 'pode' => $condominioAtual && ($usuario->can(\App\Enums\Permissao::VisitasAutorizar->value) || $usuario->can(\App\Enums\Permissao::HistoricoVer->value))],
        ['rota' => 'acessos.historico', 'ativo' => 'acessos.historico', 'icone' => 'clock', 'nome' => __('Histórico'), 'pode' => $condominioAtual && ($usuario->can(\App\Enums\Permissao::HistoricoVer->value) || $usuario->can(\App\Enums\Permissao::HistoricoProprioVer->value))],
        ['rota' => 'dashboard', 'ativo' => 'dashboard', 'icone' => 'home', 'nome' => __('Painel'), 'pode' => $usuario->isSuperAdmin() || \App\Support\RegrasDeAcesso::temPerfilOperacionalEmAlgum($usuario)],
    ])->where('pode', true)->take(4);
@endphp

<nav class="fixed inset-x-0 bottom-0 z-40 border-t border-zinc-200 bg-white/95 backdrop-blur lg:hidden dark:border-zinc-700 dark:bg-zinc-900/95"
    style="padding-bottom: env(safe-area-inset-bottom)" aria-label="{{ __('Navegação principal') }}">
    <div class="mx-auto flex max-w-lg">
        @foreach ($itens as $item)
            <a href="{{ route($item['rota']) }}" wire:navigate
                @class([
                    'flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-medium',
                    'text-zinc-900 dark:text-white' => request()->routeIs($item['ativo']),
                    'text-zinc-500 dark:text-zinc-400' => ! request()->routeIs($item['ativo']),
                ])>
                <flux:icon :name="$item['icone']" :variant="request()->routeIs($item['ativo']) ? 'solid' : 'outline'" class="size-6" />
                {{ $item['nome'] }}
            </a>
        @endforeach
        <button type="button" x-data x-on:click="document.querySelector('[data-flux-sidebar-toggle]')?.click()"
            class="flex flex-1 flex-col items-center gap-0.5 py-2 text-[11px] font-medium text-zinc-500 dark:text-zinc-400">
            <flux:icon.bars-3 class="size-6" />
            {{ __('Mais') }}
        </button>
    </div>
</nav>
