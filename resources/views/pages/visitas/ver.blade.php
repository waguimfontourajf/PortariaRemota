<?php

use App\Enums\Permissao;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Visita;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Visitante')] class extends Component {
    use OperaNoCondominioAtual;

    #[Locked]
    public int $visitaId;

    public function mount(Visita $visita): void
    {
        $this->garantirDoCondominio($visita);
        $this->autorizar($visita);
        $this->visitaId = $visita->id;
    }

    public function visita(): Visita
    {
        $visita = Visita::with(['pontos', 'autor', 'unidade.bloco', 'eventos' => fn ($q) => $q->latest('id')])->findOrFail($this->visitaId);
        $this->autorizar($visita);

        return $visita;
    }

    /** O link só aparece para quem criou (o morador envia ao visitante). */
    public function ehAutor(Visita $visita): bool
    {
        return $visita->user_id === auth()->id();
    }

    public function cancelar(): void
    {
        $visita = $this->visita();
        $visita->forceFill(['revogada_em' => now()])->save();
        activity('visita')->causedBy(auth()->user())->performedOn($visita)
            ->withProperties(['condominio_id' => $visita->condominio_id])->log('Autorização de visitante cancelada');
        Flux::toast(variant: 'success', text: __('Autorização cancelada.'));
    }

    private function autorizar(Visita $visita): void
    {
        $u = auth()->user();
        abort_unless($visita->user_id === $u->id || $u->can(Permissao::HistoricoVer->value), 404);
    }
}; ?>

@php($v = $this->visita())
@php($fuso = $this->condominio()->fuso_horario)
@php($situacao = $v->situacao())
@php($mensagem = __('Olá, :nome! Seu acesso ao :condominio está liberado. Abra o portão por este link quando chegar: :link', ['nome' => $v->nome, 'condominio' => $this->condominio()->nome, 'link' => $v->link()]))

<section class="w-full max-w-xl" x-on:tempo-real-acessos.window="$wire.$refresh()">
    <x-cabecalho-pagina :titulo="$v->nome" :subtitulo="__('Autorização de visitante')">
        <x-slot:acoes>
            <flux:button variant="ghost" icon="arrow-left" :href="route('visitas.index')" wire:navigate>{{ __('Voltar') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    @if ($this->ehAutor($v) && in_array($situacao, ['ativa', 'agendada'], true))
        <div class="mb-6 rounded-2xl border border-zinc-200 bg-white p-5 text-center dark:border-zinc-700 dark:bg-zinc-900"
            x-data="{ link: @js($v->link()), mensagem: @js($mensagem), copiado: false }">
            <div class="mx-auto w-56 rounded-xl bg-white p-2" x-qrcode="link"></div>
            <flux:text class="mt-3 text-sm">{{ __('Envie o link ao visitante. Ele abre no celular e toca em ABRIR ao chegar — não precisa de cadastro.') }}</flux:text>

            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                @php($fone = preg_replace('/\D/', '', (string) $v->telefone))
                <a href="https://wa.me/{{ strlen($fone) >= 10 ? '55'.$fone : '' }}?text={{ rawurlencode($mensagem) }}" target="_blank" rel="noopener"
                    class="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-green-600 px-4 text-sm font-medium text-white hover:bg-green-700">
                    <flux:icon.chat-bubble-left-ellipsis variant="micro" /> {{ __('Enviar pelo WhatsApp') }}
                </a>
                <flux:button icon="share" x-on:click="navigator.share ? navigator.share({ title: @js(__('Acesso liberado')), text: mensagem }) : (navigator.clipboard.writeText(mensagem), copiado = true)">
                    <span x-text="copiado ? @js(__('Mensagem copiada!')) : @js(__('Compartilhar / copiar'))"></span>
                </flux:button>
            </div>
        </div>
    @endif

    <flux:card class="space-y-3 text-sm">
        <div class="flex items-center justify-between">
            <span class="text-zinc-500">{{ __('Situação') }}</span>
            <flux:badge size="sm" :color="['ativa' => 'green', 'agendada' => 'blue', 'usada' => 'zinc', 'expirada' => 'zinc', 'cancelada' => 'red'][$situacao]">
                {{ ['ativa' => __('Válida'), 'agendada' => __('Agendada'), 'usada' => __('Usada'), 'expirada' => __('Expirada'), 'cancelada' => __('Cancelada')][$situacao] }}
            </flux:badge>
        </div>
        <div class="flex justify-between"><span class="text-zinc-500">{{ __('Validade') }}</span><span>{{ $v->valido_de->timezone($fuso)->format('d/m H:i') }} → {{ $v->valido_ate->timezone($fuso)->format('d/m H:i') }}</span></div>
        <div class="flex justify-between"><span class="text-zinc-500">{{ __('Entradas') }}</span><span>{{ $v->usos }} / {{ $v->usos_maximos }}</span></div>
        <div class="flex justify-between gap-4"><span class="text-zinc-500">{{ __('Portões') }}</span><span class="text-end">{{ $v->pontos->pluck('nome')->join(', ') }}</span></div>
        <div class="flex justify-between"><span class="text-zinc-500">{{ __('Autorizado por') }}</span><span>{{ $v->autor?->name ?? '—' }}{{ $v->unidade ? ' · '.$v->unidade->identificacao : '' }}</span></div>
        @if ($v->telefone)<div class="flex justify-between"><span class="text-zinc-500">{{ __('Celular') }}</span><span>{{ $v->telefone }}</span></div>@endif
        @if ($v->documento)<div class="flex justify-between"><span class="text-zinc-500">{{ __('Documento') }}</span><span>{{ $v->documento }}</span></div>@endif
        @if ($v->observacao)<div><span class="text-zinc-500">{{ __('Observação') }}:</span> {{ $v->observacao }}</div>@endif
    </flux:card>

    <flux:heading class="mt-6 mb-2">{{ __('Uso do link') }}</flux:heading>
    @forelse ($v->eventos as $e)
        <div class="flex items-center gap-3 py-1.5 text-sm">
            <flux:badge size="sm" :color="$e->resultado->cor()">{{ $e->resultado->label() }}</flux:badge>
            <span class="font-medium">{{ $e->ponto_nome }}</span>
            <span class="ms-auto text-zinc-500">{{ $e->created_at->timezone($fuso)->format('d/m H:i:s') }}</span>
        </div>
    @empty
        <flux:text class="text-sm">{{ __('O visitante ainda não usou o link.') }}</flux:text>
    @endforelse

    @if (in_array($situacao, ['ativa', 'agendada'], true))
        <flux:button class="mt-6 w-full" variant="danger" icon="x-mark" wire:click="cancelar" wire:confirm="{{ __('Cancelar esta autorização? O link deixa de funcionar.') }}">{{ __('Cancelar autorização') }}</flux:button>
    @endif
</section>
