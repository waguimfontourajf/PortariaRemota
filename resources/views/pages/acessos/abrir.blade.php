<?php

use App\Acoes\AcionarPontoDeAcesso;
use App\Enums\AcaoEvento;
use App\Enums\OrigemEvento;
use App\Enums\Permissao;
use App\Enums\ResultadoEvento;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\EventoAcesso;
use App\Models\PontoAcesso;
use App\Support\RegrasDeAcesso;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Abrir')] class extends Component {
    use OperaNoCondominioAtual;

    /** @var array<int, array{sucesso: bool, texto: string}> */
    public array $resultados = [];

    public bool $estadosConsultados = false;

    public function mount(): void
    {
        $this->exigirPermissao(Permissao::AcessosAbrir);
    }

    #[Computed]
    public function pontos()
    {
        $usuario = auth()->user();

        return $this->condominio()->pontosAcesso()
            ->with(['condominio', 'dispositivo:id,online,ativo,driver', 'cameras'])
            ->where('ativo', true)
            ->orderBy('ordem')->orderBy('nome')
            ->get()
            ->filter(fn (PontoAcesso $p) => RegrasDeAcesso::podeAbrir($usuario, $p))
            ->values();
    }

    #[Computed]
    public function ultimos()
    {
        return EventoAcesso::query()
            ->whereIn('id', EventoAcesso::query()->selectRaw('MAX(id)')
                ->whereIn('ponto_acesso_id', $this->pontos->pluck('id'))
                ->where('resultado', ResultadoEvento::Sucesso)
                ->groupBy('ponto_acesso_id'))
            ->get()
            ->keyBy('ponto_acesso_id');
    }

    /**
     * @param  bool|null  $ligar  Só para pontos "liga / desliga".
     */
    public function abrir(string $uuid, ?bool $ligar = null): void
    {
        $this->exigirPermissao(Permissao::AcessosAbrir);
        $ponto = $this->condominio()->pontosAcesso()->where('uuid', $uuid)->firstOrFail();

        $evento = app(AcionarPontoDeAcesso::class)($ponto, auth()->user(), OrigemEvento::Web, request()->ip(), $ponto->ehLigaDesliga() ? $ligar : null);
        $sucesso = $evento->resultado === ResultadoEvento::Sucesso;
        $hora = now()->timezone($this->condominio()->fuso_horario)->format('H:i:s');

        $texto = match (true) {
            ! $sucesso => $evento->detalhe,
            $evento->acao === AcaoEvento::Ligar => __('Ligado às :h', ['h' => $hora]),
            $evento->acao === AcaoEvento::Desligar => __('Desligado às :h', ['h' => $hora]),
            default => __('Aberto às :h', ['h' => $hora]),
        };

        $this->resultados[$ponto->id] = ['sucesso' => $sucesso, 'texto' => $texto];

        Flux::toast(
            variant: $sucesso ? 'success' : 'danger',
            heading: $ponto->nome,
            text: $sucesso ? ($ponto->ehLigaDesliga() ? $texto.'.' : __('Comando de abertura enviado.')) : $evento->detalhe,
        );

        unset($this->pontos, $this->ultimos);
    }

    /**
     * Consulta o estado real dos pontos "liga / desliga" (chamado logo após a
     * tela carregar, para não atrasar a abertura da página).
     */
    public function atualizarEstados(): void
    {
        $this->exigirPermissao(Permissao::AcessosAbrir);

        foreach ($this->pontos->filter->ehLigaDesliga() as $ponto) {
            $dispositivo = $ponto->dispositivo()->first();

            if (! $dispositivo || ! $dispositivo->ativo) {
                continue;
            }

            $resultado = $dispositivo->driverDeRele()->estado($dispositivo, $ponto->canal);
            $dispositivo->registrarContato($resultado->sucesso, $resultado->sucesso ? null : $resultado->mensagem);

            if ($resultado->sucesso && $resultado->ligado !== null) {
                $ponto->registrarEstado($resultado->ligado);
            }
        }

        $this->estadosConsultados = true;
        unset($this->pontos);
    }
}; ?>

<section class="w-full" x-on:tempo-real-acessos.window="$wire.$refresh()">
    <x-convite-instalar />

    <x-cabecalho-pagina :titulo="__('Abrir')" :subtitulo="$this->condominio()->nome" />

    @if ($this->pontos->isEmpty())
        <flux:callout icon="information-circle" variant="secondary">
            <flux:callout.text>{{ __('Nenhum portão ou porta disponível para você neste condomínio.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" @if ($this->pontos->contains->ehLigaDesliga()) wire:init="atualizarEstados" @endif>
            @foreach ($this->pontos as $ponto)
                @php($ultimo = $this->ultimos->get($ponto->id))
                @php($resultado = $resultados[$ponto->id] ?? null)
                <div wire:key="ponto-{{ $ponto->id }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start gap-3">
                        <div class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                            <flux:icon :name="$ponto->tipo->icone()" class="size-6 text-zinc-600 dark:text-zinc-300" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <flux:heading size="lg" class="truncate">{{ $ponto->nome }}</flux:heading>
                            <flux:text class="text-sm">{{ $ponto->tipo->label() }}</flux:text>
                        </div>
                        @if ($ponto->dispositivo?->online === false)
                            <flux:tooltip :content="__('O equipamento não respondeu no último contato.')">
                                <flux:icon.signal-slash variant="mini" class="text-red-500" />
                            </flux:tooltip>
                        @endif
                    </div>

                    @php($camera = $ponto->cameras->first(fn ($c) => \App\Cameras\Cameras::podeVer(auth()->user(), $c)))
                    @if ($camera)
                        <x-camera-ao-vivo :camera="$camera" compacta wire:key="cam-{{ $ponto->id }}-{{ $camera->id }}" wire:ignore />
                    @endif

                    @if ($ponto->ehLigaDesliga())
                        @php($ligado = $ponto->ligado)
                        <div class="flex items-center gap-2 text-sm">
                            <span @class([
                                'inline-block size-2.5 rounded-full',
                                'bg-amber-400 shadow-[0_0_8px] shadow-amber-400' => $ligado === true,
                                'bg-zinc-300 dark:bg-zinc-600' => $ligado === false,
                                'bg-zinc-200 dark:bg-zinc-700 animate-pulse' => $ligado === null,
                            ])></span>
                            <span class="text-zinc-600 dark:text-zinc-300">
                                @if ($ligado === null)
                                    {{ $estadosConsultados ? __('Estado desconhecido') : __('Consultando…') }}
                                @else
                                    {{ $ligado ? __('Ligado') : __('Desligado') }}
                                @endif
                            </span>
                        </div>

                        <flux:button
                            :variant="$ligado ? 'filled' : 'primary'"
                            class="h-14! w-full text-base!"
                            :icon="$ligado ? 'power' : 'bolt'"
                            wire:click="abrir('{{ $ponto->uuid }}', {{ $ligado === null ? 'null' : ($ligado ? 'false' : 'true') }})"
                            wire:loading.attr="disabled"
                            wire:target="abrir"
                            data-test="abrir-{{ $ponto->uuid }}"
                        >
                            <span wire:loading.remove wire:target="abrir">
                                @if ($ligado === null)
                                    {{ __('LIGAR / DESLIGAR') }}
                                @else
                                    {{ $ligado ? __('DESLIGAR') : __('LIGAR') }}
                                @endif
                            </span>
                            <span wire:loading wire:target="abrir">{{ __('Enviando…') }}</span>
                        </flux:button>
                    @else
                        <flux:button
                            variant="primary"
                            class="h-14! w-full text-base!"
                            icon="lock-open"
                            wire:click="abrir('{{ $ponto->uuid }}')"
                            wire:loading.attr="disabled"
                            wire:target="abrir('{{ $ponto->uuid }}')"
                            data-test="abrir-{{ $ponto->uuid }}"
                        >
                            <span wire:loading.remove wire:target="abrir('{{ $ponto->uuid }}')">{{ __('ABRIR') }}</span>
                            <span wire:loading wire:target="abrir('{{ $ponto->uuid }}')">{{ __('Abrindo…') }}</span>
                        </flux:button>
                    @endif

                    <flux:text class="min-h-5 text-xs">
                        @if ($resultado)
                            <span @class(['text-green-600 dark:text-green-400' => $resultado['sucesso'], 'text-red-600 dark:text-red-400' => ! $resultado['sucesso']])>{{ $resultado['texto'] }}</span>
                        @elseif ($ultimo)
                            {{ __($ponto->ehLigaDesliga() ? 'Última ação: :data por :quem' : 'Última abertura: :data por :quem', [
                                'data' => $ultimo->created_at->timezone($this->condominio()->fuso_horario)->format('d/m H:i'),
                                'quem' => $ultimo->usuario_nome ?? __('sistema'),
                            ]) }}
                        @endif
                    </flux:text>
                </div>
            @endforeach
        </div>
    @endif
</section>
