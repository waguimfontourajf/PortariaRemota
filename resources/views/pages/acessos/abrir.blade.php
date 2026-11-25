<?php

use App\Acoes\AcionarPontoDeAcesso;
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

    public function mount(): void
    {
        $this->exigirPermissao(Permissao::AcessosAbrir);
    }

    #[Computed]
    public function pontos()
    {
        $usuario = auth()->user();

        return $this->condominio()->pontosAcesso()
            ->with(['condominio', 'dispositivo:id,online,ativo'])
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

    public function abrir(string $uuid, AcionarPontoDeAcesso $acionar): void
    {
        $this->exigirPermissao(Permissao::AcessosAbrir);
        $ponto = $this->condominio()->pontosAcesso()->where('uuid', $uuid)->firstOrFail();

        $evento = $acionar($ponto, auth()->user(), OrigemEvento::Web, request()->ip());
        $sucesso = $evento->resultado === ResultadoEvento::Sucesso;

        $this->resultados[$ponto->id] = [
            'sucesso' => $sucesso,
            'texto' => $sucesso ? __('Aberto às :h', ['h' => now()->timezone($this->condominio()->fuso_horario)->format('H:i:s')]) : $evento->detalhe,
        ];

        Flux::toast(
            variant: $sucesso ? 'success' : 'danger',
            heading: $ponto->nome,
            text: $sucesso ? __('Comando de abertura enviado.') : $evento->detalhe,
        );

        unset($this->ultimos);
    }
}; ?>

<section class="w-full">
    <x-cabecalho-pagina :titulo="__('Abrir')" :subtitulo="$this->condominio()->nome" />

    @if ($this->pontos->isEmpty())
        <flux:callout icon="information-circle" variant="secondary">
            <flux:callout.text>{{ __('Nenhum portão ou porta disponível para você neste condomínio.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
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

                    <flux:text class="min-h-5 text-xs">
                        @if ($resultado)
                            <span @class(['text-green-600 dark:text-green-400' => $resultado['sucesso'], 'text-red-600 dark:text-red-400' => ! $resultado['sucesso']])>{{ $resultado['texto'] }}</span>
                        @elseif ($ultimo)
                            {{ __('Última abertura: :data por :quem', [
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
