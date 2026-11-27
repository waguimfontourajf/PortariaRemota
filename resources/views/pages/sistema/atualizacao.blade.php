<?php

use App\Support\AtualizacaoDoSistema;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Atualizar sistema')] class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }

    #[Computed]
    public function atualizacao(): AtualizacaoDoSistema
    {
        return app(AtualizacaoDoSistema::class);
    }

    #[Computed]
    public function tarefaInstalada(): bool
    {
        return $this->atualizacao->tarefaInstalada();
    }

    public function atualizar(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        try {
            $this->atualizacao->iniciar();
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        activity('sistema')->causedBy(auth()->user())->log('Atualização do sistema iniciada pelo painel');

        $this->dispatch('atualizacao-iniciada');
    }
}; ?>

@php($versao = $this->atualizacao->versao())
@php($estado = $this->atualizacao->estado())

<section class="w-full max-w-4xl"
    x-data="{
        estado: @js($estado),
        registro: @js($this->atualizacao->registro()),
        versao: @js($versao ? $versao['commit'].' — '.$versao['mensagem'] : null),
        timer: null,
        acompanhar() {
            clearInterval(this.timer);
            this.timer = setInterval(async () => {
                try {
                    const r = await fetch(@js(route('sistema.atualizacao.estado')), { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                    if (! r.ok) return; // durante a troca de arquivos o servidor pode falhar por instantes
                    const d = await r.json();
                    this.estado = d.estado; this.registro = d.registro; this.versao = d.versao;
                    this.$nextTick(() => { const el = this.$refs.log; if (el) el.scrollTop = el.scrollHeight; });
                    if (d.estado !== 'executando') { clearInterval(this.timer); }
                } catch (e) {}
            }, 3000);
        },
    }"
    x-init="if (estado === 'executando') acompanhar()"
    x-on:atualizacao-iniciada.window="estado = 'executando'; registro = ''; acompanhar()"
>
    <x-cabecalho-pagina :titulo="__('Atualizar sistema')" :subtitulo="__('Baixa a versão mais recente do GitHub e instala neste servidor.')" />

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:card class="flex flex-col gap-1">
            <flux:text class="text-sm">{{ __('Versão instalada') }}</flux:text>
            <flux:heading class="font-mono text-sm" x-text="versao ?? @js(__('desconhecida (rode a atualização uma vez)'))"></flux:heading>
            @if ($versao && $versao['data'])
                <flux:text class="text-xs">{{ $versao['data']->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</flux:text>
            @endif
        </flux:card>

        <flux:card class="flex flex-col gap-2">
            <flux:text class="text-sm">{{ __('Situação') }}</flux:text>
            <div>
                <template x-if="estado === 'executando'"><flux:badge color="blue" icon="arrow-path">{{ __('Atualizando… (1 a 3 minutos)') }}</flux:badge></template>
                <template x-if="estado === 'sucesso'"><flux:badge color="green" icon="check-circle">{{ __('Última atualização concluída') }}</flux:badge></template>
                <template x-if="estado === 'erro'"><flux:badge color="red" icon="x-circle">{{ __('A última atualização falhou') }}</flux:badge></template>
                <template x-if="estado === 'travada'"><flux:badge color="amber" icon="exclamation-triangle">{{ __('Parece travada (sem resposta há muito tempo)') }}</flux:badge></template>
                <template x-if="estado === 'nunca'"><flux:badge color="zinc">{{ __('Nenhuma atualização pelo painel ainda') }}</flux:badge></template>
            </div>
        </flux:card>
    </div>

    <div class="mt-6">
        @if (! $this->atualizacao->disponivel())
            <flux:callout variant="secondary" icon="information-circle" :heading="__('Disponível apenas no servidor Windows')">
                <flux:callout.text>{{ __('Este botão funciona no servidor onde o sistema está instalado. Em outros ambientes, atualize pelo terminal.') }}</flux:callout.text>
            </flux:callout>
        @elseif (! $this->tarefaInstalada)
            <flux:callout variant="warning" icon="wrench-screwdriver" :heading="__('Falta uma configuração única no servidor')">
                <flux:callout.text>
                    {{ __('No Terminal do Laragon (como administrador), dentro da pasta do projeto, rode:') }}
                    <code class="mt-2 block rounded bg-zinc-100 p-2 font-mono text-xs dark:bg-zinc-800">implantacao\instalar-atualizacao-pelo-painel.bat</code>
                </flux:callout.text>
            </flux:callout>
        @else
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="primary" icon="arrow-down-tray"
                    wire:click="atualizar"
                    wire:confirm="{{ __('Atualizar agora? O sistema fica em manutenção por 1 a 3 minutos e quem estiver usando verá uma tela de aguarde.') }}"
                    x-bind:disabled="estado === 'executando'">
                    {{ __('Atualizar agora') }}
                </flux:button>
                <flux:text class="text-sm">{{ __('Durante a atualização, os portões não podem ser abertos pelo sistema.') }}</flux:text>
            </div>
        @endif
    </div>

    <div class="mt-6" x-show="registro">
        <flux:heading class="mb-2">{{ __('Registro da atualização') }}</flux:heading>
        <pre x-ref="log" x-text="registro" class="max-h-[28rem] overflow-auto rounded-lg bg-zinc-900 p-4 font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-100"></pre>
    </div>

    <template x-if="estado === 'sucesso' && timer !== null">
        <flux:callout class="mt-6" variant="success" icon="check-circle" :heading="__('Atualização concluída!')">
            <flux:callout.text>
                <a href="{{ route('sistema.atualizacao') }}" class="underline">{{ __('Recarregar a página') }}</a>
            </flux:callout.text>
        </flux:callout>
    </template>
</section>
