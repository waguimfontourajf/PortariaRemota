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

        <flux:card class="flex flex-col gap-2">
            <flux:heading>{{ __('Em construção') }}</flux:heading>
            <flux:text>
                {{ __('Pontos de acesso, dispositivos, câmeras e interfone chegam nas próximas etapas. Esta é a Etapa 0: login, perfis e estrutura de condomínios.') }}
            </flux:text>
        </flux:card>
    </div>
</x-layouts::app>
