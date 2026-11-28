<?php

use App\Cameras\Cameras;
use App\Central\Chamadas;
use App\Enums\ResultadoEvento;
use App\Enums\StatusChamada;
use App\Models\Chamada;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Pop-up da central: aparece em qualquer tela quando um interfone toca.
 * Mostra condomínio, portão, câmera ao vivo e os botões Atender / ABRIR / Encerrar.
 */
new class extends Component {
    /** @var array<string, string> Observação digitada por chamada. */
    public array $observacoes = [];

    #[On('tempo-real-chamadas')]
    public function atualizar(): void
    {
        unset($this->chamadas);
    }

    #[Computed]
    public function chamadas()
    {
        return app(Chamadas::class)->ativasPara(auth()->user())->load('ponto.cameras');
    }

    public function atender(string $uuid): void
    {
        $chamada = $this->buscar($uuid);
        if (! app(Chamadas::class)->atender($chamada, auth()->user())) {
            Flux::toast(variant: 'warning', text: __(':nome já atendeu esta chamada.', ['nome' => $chamada->atendente?->name ?? __('Outra pessoa')]));
        }
        unset($this->chamadas);
    }

    public function abrir(string $uuid): void
    {
        $chamada = $this->buscar($uuid);
        $evento = app(Chamadas::class)->abrirPorta($chamada, auth()->user(), request()->ip());

        if (! $evento) {
            Flux::toast(variant: 'danger', text: __('Esta chamada não tem portão ligado. Ligue o interfone a um portão em Pontos de acesso.'));
        } elseif ($evento->resultado === ResultadoEvento::Sucesso) {
            Flux::toast(variant: 'success', text: __(':ponto aberto.', ['ponto' => $evento->ponto_nome]));
        } else {
            Flux::toast(variant: 'danger', text: $evento->detalhe ?? __('Não foi possível abrir.'));
        }
        unset($this->chamadas);
    }

    public function encerrar(string $uuid): void
    {
        $chamada = $this->buscar($uuid);
        $observacao = trim(mb_substr((string) ($this->observacoes[$uuid] ?? ''), 0, 1000));
        app(Chamadas::class)->encerrar($chamada, $observacao ?: null);
        unset($this->observacoes[$uuid], $this->chamadas);
    }

    private function buscar(string $uuid): Chamada
    {
        $chamada = Chamada::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless(Chamadas::podeAtender(auth()->user(), $chamada), 403);
        abort_if($chamada->status->ativa() && $chamada->status === StatusChamada::EmAtendimento && $chamada->atendida_por_id !== auth()->id(), 409, __('Outra pessoa está atendendo.'));

        return $chamada;
    }
}; ?>

<div x-data class="pointer-events-none fixed inset-x-0 bottom-20 z-50 flex flex-col items-center gap-3 px-3 lg:inset-x-auto lg:right-6 lg:bottom-6 lg:items-end" data-test="central-popup">
    @foreach ($this->chamadas as $chamada)
        @php($tocando = $chamada->status === StatusChamada::Tocando)
        @php($camera = $chamada->ponto?->cameras->first(fn ($c) => Cameras::podeVer(auth()->user(), $c)))
        @php($dtmf = $chamada->interfone?->opcao('dtmf_abrir'))
        <div wire:key="chamada-{{ $chamada->uuid }}" data-chamada="{{ $chamada->uuid }}"
            x-data="{ uuid: @js($chamada->uuid), get comAudio() { return $store.softphone.ligacoes.length > 0 && window.softphone?.temLigacao(this.uuid) } }"
            @class([
                'pointer-events-auto w-full max-w-md overflow-hidden rounded-2xl border bg-white shadow-2xl dark:bg-zinc-900',
                'relative border-amber-400 ring-4 ring-amber-400/40' => $tocando && $loop->first,
                'border-amber-400' => $tocando && ! $loop->first,
                'border-blue-400' => ! $tocando,
            ])>
            @if ($tocando && $loop->first)
                <span class="pointer-events-none absolute inset-0 animate-ping rounded-2xl border-2 border-amber-400 opacity-40 [animation-duration:2s]"></span>
            @endif
            <div class="flex items-start gap-3 p-4 pb-3">
                <div @class(['flex size-10 shrink-0 items-center justify-center rounded-full', 'bg-amber-100 text-amber-700' => $tocando, 'bg-blue-100 text-blue-700' => ! $tocando])>
                    <flux:icon :name="$tocando ? 'bell-alert' : 'phone'" class="size-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-xs font-medium uppercase tracking-wide {{ $tocando ? 'text-amber-600' : 'text-blue-600' }}">
                        {{ $tocando ? __('Interfone tocando') : __('Em atendimento — :t', ['t' => $chamada->duracaoTexto()]) }}
                        @if ($chamada->origem !== \App\Enums\OrigemChamada::Interfone)
                            · {{ $chamada->origem->label() }}
                        @endif
                    </div>
                    <div class="truncate text-lg font-semibold">{{ $chamada->titulo() }}</div>
                    <div class="truncate text-sm text-zinc-500">{{ $chamada->condominio->nome }}</div>
                </div>
            </div>

            @if ($loop->first && $camera)
                <div class="px-4">
                    <x-camera-ao-vivo :camera="$camera" compacta wire:key="cam-popup-{{ $chamada->uuid }}-{{ $camera->id }}" wire:ignore />
                </div>
            @endif

            @if ($loop->first)
                <div class="grid grid-cols-2 gap-2 p-4">
                    @if ($tocando)
                        <flux:button variant="primary" color="green" icon="phone" class="col-span-2 sm:col-span-1" data-test="atender"
                            x-on:click="window.softphone?.atender(uuid); $wire.atender(uuid)">{{ __('Atender') }}</flux:button>
                    @endif
                    @if ($chamada->ponto)
                        <flux:button variant="primary" icon="lock-open" @class(['col-span-2' => ! $tocando, 'col-span-2 sm:col-span-1' => $tocando]) data-test="abrir-chamada"
                            wire:click="abrir('{{ $chamada->uuid }}')" wire:loading.attr="disabled" wire:target="abrir">
                            {{ __('ABRIR :p', ['p' => mb_strtoupper($chamada->ponto->nome)]) }}
                        </flux:button>
                    @endif
                    @if ($dtmf)
                        <flux:button icon="key" class="col-span-2" x-show="comAudio" x-on:click="window.softphone?.dtmf(uuid, @js($dtmf))">{{ __('Abrir pelo interfone') }}</flux:button>
                    @endif
                    @unless ($tocando)
                        <div class="col-span-2">
                            <flux:input size="sm" wire:model="observacoes.{{ $chamada->uuid }}" :placeholder="__('Observação (opcional): quem era, o que foi feito...')" />
                        </div>
                        <flux:button x-show="comAudio" icon="microphone" size="sm" variant="ghost"
                            x-on:click="window.softphone?.mudo(! $store.softphone.mudo)">
                            <span x-text="$store.softphone.mudo ? '{{ __('Ligar microfone') }}' : '{{ __('Mudo') }}'"></span>
                        </flux:button>
                    @endunless
                    <flux:button variant="danger" icon="phone-x-mark" size="sm" @class(['col-span-2' => $tocando]) data-test="encerrar"
                        x-on:click="$wire.encerrar(uuid).then(() => window.softphone?.encerrar(uuid))">
                        {{ $tocando ? __('Recusar') : __('Encerrar') }}
                    </flux:button>
                </div>
                <div class="px-4 pb-3 text-xs text-zinc-500" x-show="$store.softphone.estado !== 'pronto'">
                    <span x-text="$store.softphone.mensagem"></span>
                </div>
            @endif
        </div>
    @endforeach
</div>
