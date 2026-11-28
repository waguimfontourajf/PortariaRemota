<x-layouts::app :title="__('Painel')">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-1">
            <flux:heading size="xl" level="1">
                {{ __('Olá, :nome', ['nome' => str(auth()->user()->name)->before(' ')]) }}
            </flux:heading>
            <flux:text>
                @if ($condominioAtual)
                    {{ __('Você está operando o') }} <strong>{{ $condominioAtual->nome }}</strong>.
                @else
                    {{ __('Seu usuário ainda não está vinculado a nenhum condomínio.') }}
                @endif
            </flux:text>
        </div>

        @if ($condominioAtual)
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <flux:card class="flex flex-col gap-2">
                    <flux:text class="text-sm">{{ __('Modo de operação') }}</flux:text>
                    <flux:heading size="lg">{{ $condominioAtual->modo_operacao->label() }}</flux:heading>
                </flux:card>

                <flux:card class="flex flex-col gap-2">
                    <flux:text class="text-sm">{{ __('Endereço') }}</flux:text>
                    <flux:heading size="lg" class="truncate">
                        {{ collect([$condominioAtual->cidade, $condominioAtual->uf])->filter()->join(' / ') ?: '—' }}
                    </flux:heading>
                </flux:card>

                <flux:card class="flex flex-col gap-2">
                    <flux:text class="text-sm">{{ __('Seu perfil') }}</flux:text>
                    <flux:heading size="lg">
                        @if (auth()->user()->isSuperAdmin())
                            {{ __('Super admin') }}
                        @else
                            {{ auth()->user()->getRoleNames()->map(fn ($nome) => \App\Enums\Perfil::tryFrom($nome)?->label() ?? $nome)->join(', ') ?: '—' }}
                        @endif
                    </flux:heading>
                </flux:card>

                <flux:card class="flex flex-col gap-2">
                    <flux:text class="text-sm">{{ __('Servidor') }}</flux:text>
                    <flux:heading size="lg">{{ \App\Enums\ModoServidor::atual()->label() }}</flux:heading>
                </flux:card>
            </div>
        @endif

        @if ($condominioAtual)
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ([
                    ['rotulo' => __('Blocos'), 'valor' => $condominioAtual->blocos()->count(), 'rota' => 'blocos.index', 'icone' => 'building-office'],
                    ['rotulo' => __('Unidades'), 'valor' => $condominioAtual->unidades()->count(), 'rota' => 'unidades.index', 'icone' => 'home-modern'],
                    ['rotulo' => __('Pessoas ativas'), 'valor' => $condominioAtual->pessoas()->where('ativo', true)->count(), 'rota' => 'pessoas.index', 'icone' => 'users'],
                ] as $item)
                    <flux:card class="flex items-center gap-4">
                        <flux:icon :name="$item['icone']" class="size-8 text-zinc-400" />
                        <div class="flex-1">
                            <flux:text class="text-sm">{{ $item['rotulo'] }}</flux:text>
                            <flux:heading size="xl">{{ $item['valor'] }}</flux:heading>
                        </div>
                        @can(\App\Enums\Permissao::CadastrosGerenciar->value)
                            <flux:button size="sm" variant="ghost" icon="arrow-right" :href="route($item['rota'])" wire:navigate :aria-label="__('Abrir')" />
                        @endcan
                    </flux:card>
                @endforeach
            </div>
        @endif

        @if ($condominioAtual && auth()->user()->can(\App\Enums\Permissao::HistoricoVer->value))
            <livewire:pages::painel.ultimos-acessos :condominio="$condominioAtual" :key="'ultimos-'.$condominioAtual->id" />
        @endif
    </div>
</x-layouts::app>
