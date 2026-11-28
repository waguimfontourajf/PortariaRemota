<?php

use App\Enums\Permissao;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Visita;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Visitantes')] class extends Component {
    use OperaNoCondominioAtual, WithPagination;

    #[Url(except: 'ativas')]
    public string $filtro = 'ativas';

    public function mount(): void
    {
        $this->autorizar();
    }

    public function updatingFiltro(): void
    {
        $this->resetPage();
    }

    /** Equipe (síndico, porteiro, operador) vê as visitas de todos; morador, só as suas. */
    public function verTodas(): bool
    {
        $this->condominio();

        return (bool) auth()->user()->can(Permissao::HistoricoVer->value);
    }

    public function podeCriar(): bool
    {
        return (bool) auth()->user()->can(Permissao::VisitasAutorizar->value);
    }

    #[Computed]
    public function visitas()
    {
        return Visita::query()
            ->doCondominio($this->condominio())
            ->with(['autor:id,name', 'unidade.bloco'])
            ->unless($this->verTodas(), fn ($q) => $q->where('user_id', auth()->id()))
            ->when($this->filtro === 'ativas', fn ($q) => $q->whereNull('revogada_em')->where('valido_ate', '>=', now())->whereColumn('usos', '<', 'usos_maximos'))
            ->when($this->filtro === 'hoje', fn ($q) => $q->where('valido_de', '<=', now()->endOfDay())->where('valido_ate', '>=', now()->startOfDay()))
            ->latest('id')
            ->paginate(20);
    }

    public function cancelar(int $id): void
    {
        $this->autorizar();
        $visita = Visita::query()->doCondominio($this->condominio())
            ->unless($this->verTodas(), fn ($q) => $q->where('user_id', auth()->id()))
            ->findOrFail($id);

        $visita->forceFill(['revogada_em' => now()])->save();
        activity('visita')->causedBy(auth()->user())->performedOn($visita)
            ->withProperties(['condominio_id' => $visita->condominio_id])->log('Autorização de visitante cancelada');

        Flux::toast(variant: 'success', text: __('Autorização cancelada. O link não abre mais nada.'));
        unset($this->visitas);
    }

    private function autorizar(): void
    {
        $this->condominio();
        $u = auth()->user();
        abort_unless($u->can(Permissao::VisitasAutorizar->value) || $u->can(Permissao::HistoricoVer->value), 403);
    }
}; ?>

<section class="w-full max-w-4xl">
    <x-cabecalho-pagina :titulo="__('Visitantes')" :subtitulo="__('Autorize a entrada enviando um link com QR code. O visitante abre o portão pelo celular dele.')">
        @if ($this->podeCriar())
            <x-slot:acoes>
                <flux:button variant="primary" icon="plus" :href="route('visitas.criar')" wire:navigate>{{ __('Autorizar visitante') }}</flux:button>
            </x-slot:acoes>
        @endif
    </x-cabecalho-pagina>

    <flux:radio.group wire:model.live="filtro" variant="segmented" class="mb-4 max-w-sm">
        <flux:radio value="ativas" :label="__('Válidas')" />
        <flux:radio value="hoje" :label="__('Hoje')" />
        <flux:radio value="todas" :label="__('Todas')" />
    </flux:radio.group>

    <div class="space-y-3">
        @forelse ($this->visitas as $v)
            @php($situacao = $v->situacao())
            <div wire:key="visita-{{ $v->id }}" class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <flux:icon.user class="size-5 text-zinc-500" />
                </div>
                <a href="{{ route('visitas.ver', $v) }}" wire:navigate class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="truncate font-medium text-zinc-800 dark:text-white">{{ $v->nome }}</span>
                        <flux:badge size="sm" :color="['ativa' => 'green', 'agendada' => 'blue', 'usada' => 'zinc', 'expirada' => 'zinc', 'cancelada' => 'red'][$situacao]">
                            {{ ['ativa' => __('Válida'), 'agendada' => __('Agendada'), 'usada' => __('Usada'), 'expirada' => __('Expirada'), 'cancelada' => __('Cancelada')][$situacao] }}
                        </flux:badge>
                    </div>
                    <div class="truncate text-xs text-zinc-500">
                        {{ $v->valido_de->timezone($this->condominio()->fuso_horario)->format('d/m H:i') }} → {{ $v->valido_ate->timezone($this->condominio()->fuso_horario)->format('d/m H:i') }}
                        · {{ trans_choice(':u de :m uso|:u de :m usos', $v->usos_maximos, ['u' => $v->usos, 'm' => $v->usos_maximos]) }}
                        @if ($this->verTodas())
                            · {{ __('por :quem', ['quem' => $v->autor?->name ?? '—']) }}{{ $v->unidade ? ' ('.$v->unidade->identificacao.')' : '' }}
                        @endif
                    </div>
                </a>
                @if (in_array($situacao, ['ativa', 'agendada'], true))
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="cancelar({{ $v->id }})" wire:confirm="{{ __('Cancelar a autorização de :nome? O link deixa de funcionar.', ['nome' => $v->nome]) }}" :aria-label="__('Cancelar')" />
                @endif
            </div>
        @empty
            <flux:callout icon="qr-code" variant="secondary">
                <flux:callout.text>{{ __('Nenhuma autorização aqui.') }}</flux:callout.text>
            </flux:callout>
        @endforelse

        {{ $this->visitas->links() }}
    </div>
</section>
