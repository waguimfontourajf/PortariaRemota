<?php

use App\Central\Chamadas;
use App\Central\Pabx;
use App\Enums\OrigemChamada;
use App\Enums\StatusChamada;
use App\Enums\TipoDispositivo;
use App\Models\Chamada;
use App\Models\Dispositivo;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Central de atendimento')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    public string $interfoneTeste = '';

    public function mount(): void
    {
        abort_if(Chamadas::condominiosQueAtende(auth()->user())->isEmpty(), 403);
    }

    #[On('tempo-real-chamadas')]
    public function atualizar(): void
    {
        unset($this->resumo);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function condominios()
    {
        return Chamadas::condominiosQueAtende(auth()->user());
    }

    #[Computed]
    public function historico()
    {
        return Chamada::query()
            ->whereIn('condominio_id', $this->condominios)
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->with(['condominio:id,nome,fuso_horario', 'ponto:id,nome', 'interfone:id,nome', 'atendente:id,name'])
            ->latest('id')
            ->paginate(20);
    }

    #[Computed]
    public function resumo(): array
    {
        $hoje = Chamada::query()->whereIn('condominio_id', $this->condominios)->where('created_at', '>=', now()->startOfDay());

        return [
            'hoje' => (clone $hoje)->count(),
            'perdidas' => (clone $hoje)->where('status', StatusChamada::Perdida->value)->count(),
            'abertas' => (clone $hoje)->where('porta_aberta', true)->count(),
        ];
    }

    #[Computed]
    public function interfones()
    {
        return Dispositivo::query()
            ->where('tipo', TipoDispositivo::Interfone->value)
            ->whereIn('condominio_id', $this->condominios)
            ->where('ativo', true)
            ->with('condominio:id,nome')
            ->orderBy('condominio_id')->orderBy('nome')
            ->get();
    }

    #[Computed]
    public function audioAtivo(): bool
    {
        return Pabx::audioAtivo();
    }

    /**
     * Simula o toque de um interfone (para testar a central sem o aparelho).
     */
    public function simular(): void
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);
        $interfone = $this->interfones->firstWhere('id', (int) $this->interfoneTeste);
        if (! $interfone) {
            Flux::toast(variant: 'warning', text: __('Escolha um interfone.'));

            return;
        }

        app(Chamadas::class)->iniciar($interfone, OrigemChamada::Teste);
        $this->dispatch('tempo-real-chamadas');
        $this->js('window.__tempoReal?.agora?.()');
    }
}; ?>

<section class="w-full">
    <x-cabecalho-pagina :titulo="__('Central de atendimento')" :subtitulo="__('Chamadas dos interfones de todos os condomínios que você atende')" />

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card class="space-y-3">
            <flux:heading>{{ __('Telefone') }}</flux:heading>
            @if ($this->audioAtivo)
                <div class="flex items-center gap-2 text-sm" x-data>
                    <span class="size-2.5 rounded-full"
                        :class="{ 'bg-green-500': $store.softphone.estado === 'pronto', 'bg-amber-400 animate-pulse': $store.softphone.estado === 'conectando', 'bg-red-500': $store.softphone.estado === 'erro', 'bg-zinc-400': ! ['pronto','conectando','erro'].includes($store.softphone.estado) }"></span>
                    <span x-text="$store.softphone.mensagem || '{{ __('Iniciando...') }}'" data-test="estado-softphone"></span>
                </div>
                <flux:text class="text-xs">{{ __('Use fone de ouvido com microfone. O navegador pede permissão para o microfone ao atender a primeira vez.') }}</flux:text>
            @else
                <flux:text class="text-sm">{{ __('Central sem áudio: as chamadas aparecem com câmera e botão ABRIR, mas a conversa não passa pelo navegador.') }}</flux:text>
                @if (auth()->user()->isSuperAdmin())
                    <flux:link :href="route('sistema.pabx')" wire:navigate class="text-sm">{{ __('Configurar a central telefônica') }}</flux:link>
                @endif
            @endif
            <div x-data="{ permissao: ('Notification' in window) ? Notification.permission : 'indisponivel' }" class="space-y-2">
                <flux:button size="sm" icon="bell" x-show="permissao === 'default'"
                    x-on:click="Notification.requestPermission().then(p => permissao = p)">{{ __('Avisar quando o navegador estiver em segundo plano') }}</flux:button>
                <flux:text class="text-xs" x-show="permissao === 'granted'">{{ __('Avisos do navegador ativados.') }}</flux:text>
                <flux:text class="text-xs" x-show="! $store.softphone.somLiberado">{{ __('Clique em qualquer lugar da página para liberar o som do toque.') }}</flux:text>
            </div>
        </flux:card>

        <flux:card class="grid grid-cols-3 gap-2 text-center lg:col-span-2">
            <div><div class="text-3xl font-semibold">{{ $this->resumo['hoje'] }}</div><flux:text class="text-sm">{{ __('chamadas hoje') }}</flux:text></div>
            <div><div class="text-3xl font-semibold text-red-600">{{ $this->resumo['perdidas'] }}</div><flux:text class="text-sm">{{ __('não atendidas') }}</flux:text></div>
            <div><div class="text-3xl font-semibold text-green-600">{{ $this->resumo['abertas'] }}</div><flux:text class="text-sm">{{ __('com portão aberto') }}</flux:text></div>
        </flux:card>
    </div>

    @if ($this->interfones->isNotEmpty())
        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            <flux:card class="space-y-2">
                <flux:heading>{{ __('Interfones') }}</flux:heading>
                @foreach ($this->interfones as $i)
                    <div class="flex items-center justify-between gap-2 border-b border-zinc-100 py-1.5 text-sm last:border-0 dark:border-zinc-700">
                        <div class="min-w-0"><span class="font-medium">{{ $i->nome }}</span> <span class="text-zinc-500">— {{ $i->condominio->nome }}</span></div>
                        @if ($i->ramal && $this->audioAtivo)
                            <flux:button size="xs" icon="phone-arrow-up-right" x-on:click="window.softphone?.ligar(@js($i->ramal))">{{ __('Ligar') }}</flux:button>
                        @endif
                    </div>
                @endforeach
            </flux:card>
            @if (auth()->user()->isSuperAdmin())
                <flux:card class="space-y-3">
                    <flux:heading>{{ __('Testar sem o aparelho') }}</flux:heading>
                    <flux:text class="text-sm">{{ __('Faz um interfone "tocar" na central (sem áudio), para treinar a equipe e testar câmera e ABRIR.') }}</flux:text>
                    <div class="flex gap-2">
                        <flux:select wire:model="interfoneTeste" size="sm">
                            <flux:select.option value="">{{ __('Escolha o interfone') }}</flux:select.option>
                            @foreach ($this->interfones as $i)
                                <flux:select.option :value="(string) $i->id">{{ $i->nome }} — {{ $i->condominio->nome }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button size="sm" icon="bell-alert" wire:click="simular" data-test="simular">{{ __('Tocar') }}</flux:button>
                    </div>
                </flux:card>
            @endif
        </div>
    @endif

    <div class="mt-8 flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="lg">{{ __('Histórico de chamadas') }}</flux:heading>
        <flux:select wire:model.live="status" size="sm" class="max-w-52">
            <flux:select.option value="">{{ __('Todas') }}</flux:select.option>
            @foreach (StatusChamada::cases() as $s)
                <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->historico" class="mt-3">
        <flux:table.columns>
            <flux:table.column>{{ __('Quando') }}</flux:table.column>
            <flux:table.column>{{ __('Condomínio / portão') }}</flux:table.column>
            <flux:table.column>{{ __('Situação') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Atendente') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Observação') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->historico as $c)
                <flux:table.row :key="$c->id">
                    <flux:table.cell class="whitespace-nowrap">{{ $c->created_at->timezone($c->condominio->fuso_horario)->format('d/m H:i:s') }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="font-medium">{{ $c->titulo() }}</div>
                        <div class="text-xs text-zinc-500">{{ $c->condominio->nome }} · {{ $c->origem->label() }}</div>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$c->status->cor()">{{ $c->status->label() }}</flux:badge>
                        @if ($c->porta_aberta)
                            <flux:badge size="sm" color="green" icon="lock-open">{{ __('abriu') }}</flux:badge>
                        @endif
                        @if ($c->atendida_em)
                            <div class="mt-1 text-xs text-zinc-500">{{ $c->duracaoTexto() }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="max-md:hidden">{{ $c->atendente?->name ?? '—' }}</flux:table.cell>
                    <flux:table.cell class="max-md:hidden max-w-xs truncate">{{ $c->observacao ?? '' }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('Nenhuma chamada ainda.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
