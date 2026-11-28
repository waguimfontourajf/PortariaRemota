<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <x-seletor-condominio />

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Operação')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Painel') }}
                    </flux:sidebar.item>
                    @if (\App\Central\Chamadas::condominiosQueAtende(auth()->user())->isNotEmpty())
                        <flux:sidebar.item icon="phone" :href="route('central.index')" :current="request()->routeIs('central.*')" wire:navigate>
                            {{ __('Central') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($condominioAtual && auth()->user()->can(\App\Enums\Permissao::AcessosAbrir->value))
                        <flux:sidebar.item icon="lock-open" :href="route('acessos.abrir')" :current="request()->routeIs('acessos.abrir')" wire:navigate>
                            {{ __('Abrir') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($condominioAtual && (auth()->user()->can(\App\Enums\Permissao::CamerasVer->value) || $condominioAtual->dispositivos()->where('tipo', 'camera')->get()->contains(fn ($c) => \App\Cameras\Cameras::podeVer(auth()->user(), $c))))
                        <flux:sidebar.item icon="video-camera" :href="route('cameras.index')" :current="request()->routeIs('cameras.*')" wire:navigate>
                            {{ __('Câmeras') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($condominioAtual && (auth()->user()->can(\App\Enums\Permissao::VisitasAutorizar->value) || auth()->user()->can(\App\Enums\Permissao::HistoricoVer->value)))
                        <flux:sidebar.item icon="qr-code" :href="route('visitas.index')" :current="request()->routeIs('visitas.*')" wire:navigate>
                            {{ __('Visitantes') }}
                        </flux:sidebar.item>
                    @endif
                    @if (auth()->user()->ehEquipeDaCentral())
                        <flux:sidebar.item icon="globe-americas" :href="route('rede.geral')" :current="request()->routeIs('rede.geral')" wire:navigate>
                            {{ __('Mapa geral') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($condominioAtual && (auth()->user()->can(\App\Enums\Permissao::HistoricoVer->value) || auth()->user()->can(\App\Enums\Permissao::HistoricoProprioVer->value)))
                        <flux:sidebar.item icon="clock" :href="route('acessos.historico')" :current="request()->routeIs('acessos.historico')" wire:navigate>
                            {{ __('Histórico') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>

                @if ($condominioAtual && auth()->user()->can(\App\Enums\Permissao::DispositivosGerenciar->value))
                    <flux:sidebar.group :heading="__('Equipamentos')" class="grid">
                        <flux:sidebar.item icon="cpu-chip" :href="route('dispositivos.index')" :current="request()->routeIs('dispositivos.*')" wire:navigate>
                            {{ __('Dispositivos') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="share" :href="route('rede.mapa')" :current="request()->routeIs('rede.mapa')" wire:navigate>
                            {{ __('Mapa da rede') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="arrows-right-left" :href="route('pontos.index')" :current="request()->routeIs('pontos.*')" wire:navigate>
                            {{ __('Pontos de acesso') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif

                @if ($condominioAtual && auth()->user()->can(\App\Enums\Permissao::CadastrosGerenciar->value))
                    <flux:sidebar.group :heading="__('Cadastros')" class="grid">
                        <flux:sidebar.item icon="building-office" :href="route('blocos.index')" :current="request()->routeIs('blocos.*')" wire:navigate>
                            {{ __('Blocos') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="home-modern" :href="route('unidades.index')" :current="request()->routeIs('unidades.*')" wire:navigate>
                            {{ __('Unidades') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="users" :href="route('pessoas.index')" :current="request()->routeIs('pessoas.*')" wire:navigate>
                            {{ __('Pessoas') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="clock" :href="route('horarios.index')" :current="request()->routeIs('horarios.*')" wire:navigate>
                            {{ __('Horários de acesso') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif

                @if ($condominioAtual && (auth()->user()->can(\App\Enums\Permissao::UsuariosGerenciar->value) || auth()->user()->can(\App\Enums\Permissao::CondominioGerenciar->value)) || auth()->user()->isSuperAdmin())
                    <flux:sidebar.group :heading="__('Administração')" class="grid">
                        @if ($condominioAtual && auth()->user()->can(\App\Enums\Permissao::UsuariosGerenciar->value))
                            <flux:sidebar.item icon="key" :href="route('usuarios.index')" :current="request()->routeIs('usuarios.*')" wire:navigate>
                                {{ __('Usuários') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($condominioAtual && auth()->user()->can(\App\Enums\Permissao::CondominioGerenciar->value))
                            <flux:sidebar.item icon="identification" :href="route('condominios.editar', $condominioAtual)" :current="request()->routeIs('condominios.editar') && request()->route('condominio')?->is($condominioAtual)" wire:navigate>
                                {{ __('Dados do condomínio') }}
                            </flux:sidebar.item>
                        @endif
                        @if (auth()->user()->isSuperAdmin())
                            <flux:sidebar.item icon="building-office-2" :href="route('condominios.index')" :current="request()->routeIs('condominios.index', 'condominios.criar')" wire:navigate>
                                {{ __('Todos os condomínios') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="bell-alert" :href="route('sistema.alertas')" :current="request()->routeIs('sistema.alertas')" wire:navigate>
                                {{ __('Alertas (Telegram)') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="phone" :href="route('sistema.pabx')" :current="request()->routeIs('sistema.pabx')" wire:navigate>
                                {{ __('Central telefônica') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="arrow-down-tray" :href="route('sistema.atualizacao')" :current="request()->routeIs('sistema.atualizacao')" wire:navigate>
                                {{ __('Atualizar sistema') }}
                            </flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="cog-6-tooth" :href="route('profile.edit')" :current="request()->routeIs('profile.edit', 'security.edit', 'appearance.edit')" wire:navigate>
                    {{ __('Settings') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            {{-- Indicador do tempo real --}}
            <div class="flex items-center gap-2 px-2 text-xs text-zinc-500" x-data>
                <span class="relative flex size-2.5">
                    <span class="absolute inline-flex size-full animate-ping rounded-full opacity-60" :class="$store.tempoReal.conectado ? 'bg-green-500' : 'hidden'"></span>
                    <span class="relative inline-flex size-2.5 rounded-full" :class="$store.tempoReal.conectado ? 'bg-green-500' : 'bg-zinc-400'"></span>
                </span>
                <span x-text="$store.tempoReal.conectado ? @js(__('Ao vivo')) : @js(__('Sem conexão — tentando de novo…'))"></span>
            </div>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
