<?php

use App\Models\Configuracao;
use App\Notificacoes\Telegram;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Alertas (Telegram)')] class extends Component {
    public string $token = '';
    public string $chat_id = '';
    public bool $ativo = true;
    public bool $tokenSalvo = false;

    /** @var list<array{id: string, nome: string, tipo: string}> */
    public array $conversas = [];

    public function mount(): void
    {
        $this->autorizar();
        $this->tokenSalvo = filled(Configuracao::obter('telegram.token'));
        $this->chat_id = (string) Configuracao::obter('telegram.chat_id', '');
        $this->ativo = Configuracao::obter('telegram.ativo', '1') === '1';
    }

    public function salvar(): void
    {
        $this->autorizar();
        $this->validate([
            'token' => ['nullable', 'regex:/^\d{5,}:[A-Za-z0-9_-]{20,}$/'],
            'chat_id' => ['nullable', 'regex:/^-?\d{3,20}$/'],
        ], ['token.regex' => __('O token tem o formato 123456789:ABC... (copie do BotFather).'), 'chat_id.regex' => __('O chat é um número (grupos começam com -).')]);

        if ($this->token !== '') {
            Configuracao::definir('telegram.token', $this->token);
            $this->tokenSalvo = true;
            $this->token = '';
        }

        Configuracao::definir('telegram.chat_id', $this->chat_id ?: null);
        Configuracao::definir('telegram.ativo', $this->ativo ? '1' : '0');

        activity('sistema')->causedBy(auth()->user())->log('Configurou os alertas do Telegram');
        Flux::toast(variant: 'success', text: __('Configuração salva.'));
    }

    public function procurarConversas(Telegram $telegram): void
    {
        $this->autorizar();
        $resultado = $telegram->conversasRecentes($this->token !== '' ? $this->token : null);

        if (is_string($resultado)) {
            Flux::toast(variant: 'danger', text: $resultado);

            return;
        }

        $this->conversas = $resultado;

        if ($resultado === []) {
            Flux::toast(variant: 'warning', text: __('Nenhuma conversa encontrada. Envie uma mensagem para o bot (ou no grupo onde ele está) e tente de novo.'));
        }
    }

    public function usarConversa(string $id): void
    {
        $this->autorizar();
        $this->chat_id = $id;
    }

    public function testar(Telegram $telegram): void
    {
        $this->autorizar();
        $resultado = $telegram->enviar(
            "✅ <b>Portaria Remota</b>\nTeste de alerta enviado por ".Telegram::escapar(auth()->user()->name).'.',
            $this->chat_id ?: null,
            $this->token !== '' ? $this->token : null,
        );

        Flux::toast(
            variant: $resultado === true ? 'success' : 'danger',
            text: $resultado === true ? __('Mensagem enviada! Confira no Telegram.') : $resultado,
        );
    }

    private function autorizar(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-cabecalho-pagina :titulo="__('Alertas pelo Telegram')" :subtitulo="__('Avisos quando um equipamento cai ou volta.')" />

    <flux:callout icon="information-circle" variant="secondary" class="mb-6" :heading="__('Como configurar (5 minutos)')">
        <flux:callout.text>
            <ol class="mt-1 list-decimal space-y-1 pl-5">
                <li>{!! __('No Telegram, abra o <b>@BotFather</b>, envie <code>/newbot</code>, escolha um nome e copie o <b>token</b>.') !!}</li>
                <li>{!! __('Crie um <b>grupo</b> para a sua equipe, <b>adicione o bot</b> e envie qualquer mensagem no grupo.') !!}</li>
                <li>{!! __('Cole o token abaixo, clique em <b>Procurar conversas</b> e escolha o grupo.') !!}</li>
                <li>{!! __('Clique em <b>Enviar teste</b> e depois em <b>Salvar</b>.') !!}</li>
            </ol>
        </flux:callout.text>
    </flux:callout>

    <form wire:submit="salvar" class="space-y-6" autocomplete="off">
        <flux:input type="password" viewable wire:model="token" :label="__('Token do bot')" autocomplete="new-password" data-1p-ignore
            :placeholder="$tokenSalvo ? __('•••••••• (salvo — deixe em branco para manter)') : '123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ'" />

        <div class="flex flex-wrap items-end gap-2">
            <div class="min-w-56 flex-1"><flux:input wire:model="chat_id" :label="__('Chat / grupo que recebe todos os alertas')" placeholder="-1001234567890" /></div>
            <flux:button icon="magnifying-glass" wire:click="procurarConversas">{{ __('Procurar conversas') }}</flux:button>
        </div>

        @if ($conversas)
            <div class="space-y-2">
                @foreach ($conversas as $c)
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700" wire:key="chat-{{ $c['id'] }}">
                        <span><b>{{ $c['nome'] }}</b> <span class="text-zinc-500">({{ $c['tipo'] }} · {{ $c['id'] }})</span></span>
                        <flux:button size="sm" wire:click="usarConversa('{{ $c['id'] }}')">{{ __('Usar este') }}</flux:button>
                    </div>
                @endforeach
            </div>
        @endif

        <flux:switch wire:model="ativo" :label="__('Enviar alertas')" :description="__('Desligue para pausar os avisos sem perder a configuração.')" />

        <flux:text class="text-sm">{{ __('Cada condomínio também pode ter um grupo próprio (ex.: com o síndico): em Todos os condomínios → editar → "Grupo do Telegram".') }}</flux:text>

        <div class="flex justify-end gap-2">
            <flux:button icon="paper-airplane" wire:click="testar">{{ __('Enviar teste') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="check">{{ __('Salvar') }}</flux:button>
        </div>
    </form>
</section>
