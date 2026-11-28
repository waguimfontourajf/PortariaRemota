<?php

use App\Central\Pabx;
use App\Models\Configuracao;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Central telefônica')] class extends Component {
    public string $wss = '';
    public string $dominio = '';
    public string $url_sistema = '';
    public string $stun = '';
    public bool $mostrarArquivos = false;

    public function mount(): void
    {
        $this->autorizar();
        $this->wss = (string) Configuracao::obter('pabx.wss', '');
        $this->dominio = (string) Configuracao::obter('pabx.dominio', '');
        $this->url_sistema = Pabx::urlDoSistema();
        $this->stun = (string) Pabx::stun();

        if (blank(Pabx::token())) {
            Pabx::gerarToken();
        }
    }

    public function salvar(): void
    {
        $this->autorizar();
        $this->validate([
            'wss' => ['nullable', 'regex:#^wss?://[^\s]+$#', 'max:255'],
            'dominio' => ['nullable', 'regex:/^[A-Za-z0-9.\-]+$/', 'max:255'],
            'url_sistema' => ['required', 'url', 'max:255'],
            'stun' => ['nullable', 'regex:#^(stuns?|turns?):[^\s]+$#', 'max:255'],
        ], [
            'wss.regex' => __('Use um endereço que começa com wss:// (ex.: wss://voip.seudominio.com.br/ws).'),
            'stun.regex' => __('Use o formato stun:servidor:porta.'),
        ]);

        Configuracao::definir('pabx.wss', $this->wss ?: null);
        Configuracao::definir('pabx.dominio', $this->dominio ?: null);
        Configuracao::definir('pabx.url_sistema', rtrim($this->url_sistema, '/'));
        Configuracao::definir('pabx.stun', $this->stun ?: null);

        activity('sistema')->causedBy(auth()->user())->log('Configurou a central telefônica');
        Flux::toast(variant: 'success', text: __('Configuração salva. Gere os arquivos de novo e recarregue o Asterisk.'));
    }

    public function novoToken(): void
    {
        $this->autorizar();
        Pabx::gerarToken();
        activity('sistema')->causedBy(auth()->user())->log('Trocou o token da central telefônica');
        Flux::toast(variant: 'warning', text: __('Token trocado. Atualize o extensions.conf do Asterisk.'));
    }

    public function gerarArquivos(): void
    {
        $this->autorizar();
        $this->mostrarArquivos = true;
        activity('sistema')->causedBy(auth()->user())->log('Gerou os arquivos do Asterisk');
    }

    #[Computed]
    public function arquivos(): array
    {
        if (! $this->mostrarArquivos) {
            return [];
        }

        return [
            'pjsip_portaria.conf' => Pabx::configPjsip(),
            'extensions_portaria.conf' => Pabx::configExtensoes(),
            'http.conf e rtp.conf' => Pabx::configHttpRtp(),
        ];
    }

    #[Computed]
    public function interfones()
    {
        return Pabx::interfones();
    }

    #[Computed]
    public function operadores()
    {
        return Pabx::operadores();
    }

    private function autorizar(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-cabecalho-pagina :titulo="__('Central telefônica')" :subtitulo="__('Interfones tocando na central, com áudio no navegador (Asterisk)')" />

    <div class="space-y-8">
        <flux:callout icon="information-circle" variant="secondary">
            <flux:callout.text>
                {{ __('Sem Asterisk, a central já funciona com interfones/botões de "campainha" (chamada sem áudio, com câmera e ABRIR). Para falar com o visitante pelo navegador, instale o Asterisk e preencha o endereço WebSocket abaixo. Passo a passo em docs/INTERFONE.md.') }}
            </flux:callout.text>
        </flux:callout>

        <form wire:submit="salvar" class="space-y-4" autocomplete="off">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><flux:input wire:model="wss" :label="__('Endereço WebSocket do Asterisk (softphone)')" placeholder="wss://voip-portariaremota.meusistema.api.br/ws" class="font-mono" :description:trailing="__('Em branco: central sem áudio.')" /></div>
                <flux:input wire:model="dominio" :label="__('Domínio SIP (opcional)')" :placeholder="__('Igual ao do endereço acima')" class="font-mono" />
                <flux:input wire:model="stun" :label="__('Servidor STUN')" placeholder="stun:stun.l.google.com:19302" class="font-mono" />
                <div class="sm:col-span-2"><flux:input wire:model="url_sistema" :label="__('Endereço do sistema visto pelo Asterisk')" class="font-mono" :description:trailing="__('O Asterisk avisa o sistema por este endereço quando um interfone liga.')" /></div>
            </div>
            <flux:button type="submit" variant="primary">{{ __('Salvar') }}</flux:button>
        </form>

        <flux:separator />

        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <flux:heading size="lg">{{ __('Interfones (ramais)') }}</flux:heading>
                <flux:text class="mb-3 text-sm">{{ __('Cadastre em Equipamentos → Dispositivos, tipo "Interfone", com o número do ramal.') }}</flux:text>
                @forelse ($this->interfones as $i)
                    <div class="flex items-center justify-between border-b border-zinc-100 py-2 text-sm dark:border-zinc-700">
                        <span><span class="font-mono font-medium">{{ $i->ramal }}</span> — {{ $i->nome }}</span>
                        <span class="text-zinc-500">{{ $i->condominio?->nome }}</span>
                    </div>
                @empty
                    <flux:text class="text-sm">{{ __('Nenhum interfone com ramal.') }}</flux:text>
                @endforelse
            </div>
            <div>
                <flux:heading size="lg">{{ __('Operadores (softphone)') }}</flux:heading>
                <flux:text class="mb-3 text-sm">{{ __('Quem atende chamadas ganha um ramal automaticamente.') }}</flux:text>
                @forelse ($this->operadores as $u)
                    <div class="flex items-center justify-between border-b border-zinc-100 py-2 text-sm dark:border-zinc-700">
                        <span>{{ $u->name }}</span>
                        <span class="font-mono text-zinc-500">{{ $u->ramal_sip ?? __('criado ao gerar') }}</span>
                    </div>
                @empty
                    <flux:text class="text-sm">{{ __('Ninguém com permissão para atender chamadas.') }}</flux:text>
                @endforelse
            </div>
        </div>

        <flux:separator />

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Arquivos do Asterisk') }}</flux:heading>
            <flux:text class="text-sm">{{ __('Contêm as senhas dos ramais e o token: não compartilhe. Gere de novo sempre que cadastrar interfone ou operador.') }}</flux:text>
            <div class="flex flex-wrap gap-2">
                <flux:button icon="document-text" wire:click="gerarArquivos">{{ __('Gerar arquivos') }}</flux:button>
                <flux:button icon="arrow-path" variant="ghost" wire:click="novoToken" wire:confirm="{{ __('Trocar o token? O Asterisk para de avisar o sistema até você atualizar o extensions.conf.') }}">{{ __('Trocar token') }}</flux:button>
            </div>
            @foreach ($this->arquivos as $nome => $conteudo)
                <div x-data="{ copiado: false }" class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                        <span class="font-mono text-sm font-medium">{{ $nome }}</span>
                        <flux:button size="sm" variant="ghost" icon="clipboard" x-on:click="navigator.clipboard.writeText($refs.conteudo.textContent); copiado = true; setTimeout(() => copiado = false, 2000)">
                            <span x-text="copiado ? '{{ __('Copiado!') }}' : '{{ __('Copiar') }}'"></span>
                        </flux:button>
                    </div>
                    <pre x-ref="conteudo" class="max-h-80 overflow-auto p-3 text-xs">{{ $conteudo }}</pre>
                </div>
            @endforeach
        </div>
    </div>
</section>
