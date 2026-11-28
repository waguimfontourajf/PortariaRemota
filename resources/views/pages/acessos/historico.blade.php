<?php

use App\Enums\Permissao;
use App\Enums\ResultadoEvento;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\EventoAcesso;
use App\Support\ImportacaoDePassagens;
use App\Support\SincronizacaoFacial;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Histórico')] class extends Component {
    use OperaNoCondominioAtual, WithPagination;

    #[Url(except: '')]
    public string $ponto = '';

    #[Url(except: '')]
    public string $resultado = '';

    #[Url(except: '')]
    public string $de = '';

    #[Url(except: '')]
    public string $ate = '';

    #[Url(except: '')]
    public string $busca = '';

    public function mount(): void
    {
        abort_unless($this->podeVerTudo() || auth()->user()->can(Permissao::HistoricoProprioVer->value), 403);
    }

    public function updating(string $campo): void
    {
        if (in_array($campo, ['ponto', 'resultado', 'de', 'ate', 'busca'], true)) {
            $this->resetPage();
        }
    }

    public function podeVerTudo(): bool
    {
        $this->condominio();

        return auth()->user()->can(Permissao::HistoricoVer->value);
    }

    /** Leitoras que não responderam na última importação de passagens. */
    public array $errosDasLeitoras = [];

    /**
     * Traz as passagens das leitoras faciais (chamado depois que a página
     * abre, para não atrasar a tela). Cada leitora é consultada no máximo a
     * cada 2 minutos; "Buscar agora" ignora esse intervalo.
     */
    public function importarPassagens(bool $agora = false): void
    {
        $agora = $agora && $this->podeVerTudo();
        $totais = app(ImportacaoDePassagens::class)->importarDoCondominio($this->condominio(), $agora ? 0 : 120);
        $this->errosDasLeitoras = $totais['erros'];

        if ($totais['importadas'] > 0) {
            unset($this->eventos);
        }

        if ($agora) {
            Flux::toast(variant: $totais['erros'] ? 'warning' : 'success',
                text: trans_choice('{0} Nenhuma passagem nova.|{1} 1 passagem nova.|[2,*] :count passagens novas.', $totais['importadas']));
        }
    }

    #[Computed]
    public function temLeitoras(): bool
    {
        return app(SincronizacaoFacial::class)->leitoras($this->condominio())->isNotEmpty();
    }

    #[Computed]
    public function pontos()
    {
        return $this->condominio()->pontosAcesso()->withTrashed()->orderBy('nome')->get(['id', 'nome']);
    }

    #[Computed]
    public function eventos()
    {
        $fuso = $this->condominio()->fuso_horario;

        return EventoAcesso::query()
            ->doCondominio($this->condominio())
            ->unless($this->podeVerTudo(), fn ($q) => $q->where('user_id', auth()->id()))
            ->when($this->ponto !== '', fn ($q) => $q->where('ponto_acesso_id', (int) $this->ponto))
            ->when($this->resultado !== '', fn ($q) => $q->where('resultado', $this->resultado))
            ->when($this->de !== '', fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->de, $fuso)->startOfDay()->utc()))
            ->when($this->ate !== '', fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->ate, $fuso)->endOfDay()->utc()))
            ->when($this->busca !== '', fn ($q) => $q->where('usuario_nome', 'like', "%{$this->busca}%"))
            ->latest('id')
            ->paginate(50);
    }
}; ?>

<section class="w-full" x-on:tempo-real-acessos.window="$wire.$refresh()" @if ($this->temLeitoras) wire:init="importarPassagens" @endif>
    <x-cabecalho-pagina :titulo="__('Histórico de acessos')" :subtitulo="$this->podeVerTudo() ? __('Todas as aberturas e tentativas neste condomínio.') : __('Suas aberturas neste condomínio.')">
        @if ($this->temLeitoras && $this->podeVerTudo())
            <x-slot:acoes>
                <flux:button icon="arrow-path" wire:click="importarPassagens(true)" wire:target="importarPassagens" wire:loading.attr="disabled">{{ __('Buscar passagens das leitoras') }}</flux:button>
            </x-slot:acoes>
        @endif
    </x-cabecalho-pagina>

    @if ($errosDasLeitoras && $this->podeVerTudo())
        <flux:callout class="mb-4" variant="warning" icon="exclamation-triangle" :heading="__('Não foi possível ler as passagens de alguma leitora')">
            <flux:callout.text>{{ implode(' | ', $errosDasLeitoras) }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <flux:select wire:model.live="ponto">
            <flux:select.option value="">{{ __('Todos os pontos') }}</flux:select.option>
            @foreach ($this->pontos as $p)
                <flux:select.option :value="$p->id">{{ $p->nome }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="resultado">
            <flux:select.option value="">{{ __('Todos os resultados') }}</flux:select.option>
            @foreach (ResultadoEvento::cases() as $r)
                <flux:select.option :value="$r->value">{{ $r->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="de" type="date" :aria-label="__('De')" />
        <flux:input wire:model.live="ate" type="date" :aria-label="__('Até')" />
        @if ($this->podeVerTudo())
            <flux:input wire:model.live.debounce.400ms="busca" icon="magnifying-glass" :placeholder="__('Usuário')" />
        @endif
    </div>

    <flux:table :paginate="$this->eventos">
        <flux:table.columns>
            <flux:table.column>{{ __('Data e hora') }}</flux:table.column>
            <flux:table.column>{{ __('Ponto') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden">{{ __('Ação') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Quem') }}</flux:table.column>
            <flux:table.column class="max-lg:hidden">{{ __('Origem') }}</flux:table.column>
            <flux:table.column>{{ __('Resultado') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->eventos as $e)
                <flux:table.row :key="$e->id" @class(['transition-colors duration-1000', 'bg-amber-100 dark:bg-amber-900/40' => $e->created_at->gt(now()->subSeconds(10))])>
                    <flux:table.cell class="whitespace-nowrap">{{ $e->created_at->timezone($this->condominio()->fuso_horario)->format('d/m/Y H:i:s') }}</flux:table.cell>
                    <flux:table.cell variant="strong">{{ $e->ponto_nome ?? '—' }}</flux:table.cell>
                    <flux:table.cell class="max-sm:hidden">{{ $e->acao->label() }}</flux:table.cell>
                    <flux:table.cell class="max-md:hidden">{{ $e->usuario_nome ?? __('Sistema') }}</flux:table.cell>
                    <flux:table.cell class="max-lg:hidden">{{ $e->origem->label() }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:tooltip :content="$e->detalhe.($e->duracao_ms !== null ? ' ('.$e->duracao_ms.' ms)' : '')">
                            <flux:badge size="sm" :color="$e->resultado->cor()" inset="top bottom">{{ $e->resultado->label() }}</flux:badge>
                        </flux:tooltip>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="py-8 text-center">{{ __('Nenhum registro encontrado.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
