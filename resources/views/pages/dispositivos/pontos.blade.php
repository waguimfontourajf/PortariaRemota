<?php

use App\Enums\ModoAcionamento;
use App\Enums\Permissao;
use App\Enums\TipoPontoAcesso;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\PontoAcesso;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pontos de acesso')] class extends Component {
    use OperaNoCondominioAtual;

    #[Locked]
    public ?int $pontoId = null;

    public string $nome = '';
    public string $tipo = 'portao_social';
    public string $dispositivo_id = '';
    public int $canal = 1;
    public string $modo_acionamento = 'pulso';
    public int $tempo_pulso_ms = 1000;
    public bool $liberado_moradores = false;
    public bool $liberado_visitantes = false;
    public bool $ativo = true;
    public int $ordem = 0;
    public string $observacoes = '';

    public function mount(): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
    }

    #[Computed]
    public function pontos()
    {
        return $this->condominio()->pontosAcesso()->with('dispositivo')->orderBy('ordem')->orderBy('nome')->get();
    }

    #[Computed]
    public function dispositivos()
    {
        return $this->condominio()->dispositivos()->where('tipo', 'rele')->orderBy('nome')->get();
    }

    public function novo(): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        $this->reset('pontoId', 'nome', 'tipo', 'canal', 'modo_acionamento', 'tempo_pulso_ms', 'liberado_moradores', 'liberado_visitantes', 'ativo', 'observacoes');
        $this->dispositivo_id = (string) ($this->dispositivos->first()?->id ?? '');
        $this->ordem = ($this->pontos->max('ordem') ?? 0) + 1;
        $this->resetValidation();
        Flux::modal('ponto')->show();
    }

    public function editar(int $id): void
    {
        $ponto = $this->buscar($id);
        $this->pontoId = $ponto->id;
        $this->fill([
            'nome' => $ponto->nome,
            'tipo' => $ponto->tipo->value,
            'dispositivo_id' => (string) $ponto->dispositivo_id,
            'canal' => $ponto->canal,
            'modo_acionamento' => $ponto->modo_acionamento->value,
            'tempo_pulso_ms' => $ponto->tempo_pulso_ms,
            'liberado_moradores' => $ponto->liberado_moradores,
            'liberado_visitantes' => $ponto->liberado_visitantes,
            'ativo' => $ponto->ativo,
            'ordem' => $ponto->ordem,
            'observacoes' => (string) $ponto->observacoes,
        ]);
        $this->resetValidation();
        Flux::modal('ponto')->show();
    }

    public function salvar(): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        $condominioId = $this->condominio()->id;
        $dispositivo = $this->condominio()->dispositivos()->find((int) $this->dispositivo_id);

        $dados = $this->validate([
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::enum(TipoPontoAcesso::class)],
            'dispositivo_id' => ['required', Rule::exists('dispositivos', 'id')->where('condominio_id', $condominioId)->whereNull('deleted_at')],
            'canal' => ['required', 'integer', 'min:1', 'max:'.($dispositivo?->canais ?? 1)],
            'modo_acionamento' => ['required', Rule::enum(ModoAcionamento::class)],
            'tempo_pulso_ms' => ['required', 'integer', 'min:'.config('portaria.acionamento.pulso_minimo_ms'), 'max:'.config('portaria.acionamento.pulso_maximo_ms')],
            'liberado_moradores' => ['boolean'],
            'liberado_visitantes' => ['boolean'],
            'ativo' => ['boolean'],
            'ordem' => ['integer', 'min:0', 'max:999'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
        ], attributes: ['dispositivo_id' => 'dispositivo', 'tempo_pulso_ms' => 'tempo do pulso']);

        $dados['observacoes'] = $dados['observacoes'] ?: null;

        if ($this->pontoId) {
            $this->buscar($this->pontoId)->update($dados);
        } else {
            $this->condominio()->pontosAcesso()->create($dados);
        }

        Flux::modal('ponto')->close();
        Flux::toast(variant: 'success', text: __('Ponto de acesso salvo.'));
        unset($this->pontos);
    }

    public function excluir(int $id): void
    {
        $this->buscar($id)->delete();
        Flux::toast(variant: 'success', text: __('Ponto de acesso excluído. O histórico foi mantido.'));
        unset($this->pontos);
    }

    private function buscar(int $id): PontoAcesso
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);

        return $this->condominio()->pontosAcesso()->findOrFail($id);
    }
}; ?>

<section class="w-full">
    <x-cabecalho-pagina :titulo="__('Pontos de acesso')" :subtitulo="__('Portões, portas e cancelas, e qual relé aciona cada um.')">
        <x-slot:acoes>
            <flux:button variant="primary" icon="plus" wire:click="novo" :disabled="$this->dispositivos->isEmpty()">{{ __('Novo ponto de acesso') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    @if ($this->dispositivos->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
            <flux:callout.text>
                {{ __('Cadastre primeiro um dispositivo (relé).') }}
                <flux:callout.link :href="route('dispositivos.criar')" wire:navigate>{{ __('Cadastrar dispositivo') }}</flux:callout.link>
            </flux:callout.text>
        </flux:callout>
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Nome') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Dispositivo / canal') }}</flux:table.column>
            <flux:table.column class="max-lg:hidden">{{ __('Acionamento') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden">{{ __('Moradores') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->pontos as $p)
                <flux:table.row :key="$p->id">
                    <flux:table.cell>
                        <div class="flex items-center gap-2">
                            <flux:icon :name="$p->tipo->icone()" variant="mini" class="text-zinc-400" />
                            <span class="font-medium text-zinc-800 dark:text-white">{{ $p->nome }}</span>
                            @unless ($p->ativo)
                                <flux:badge size="sm" color="zinc">{{ __('Inativo') }}</flux:badge>
                            @endunless
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="max-md:hidden">{{ $p->dispositivo?->nome ?? '—' }} · {{ __('canal :c', ['c' => $p->canal]) }}</flux:table.cell>
                    <flux:table.cell class="max-lg:hidden">
                        {{ $p->modo_acionamento === ModoAcionamento::Pulso ? __('Pulso de :s s', ['s' => number_format($p->tempo_pulso_ms / 1000, 1, ',', '')]) : $p->modo_acionamento->label() }}
                    </flux:table.cell>
                    <flux:table.cell class="max-sm:hidden">
                        <flux:badge size="sm" :color="$p->liberado_moradores ? 'green' : 'zinc'">{{ $p->liberado_moradores ? __('Liberado') : __('Somente equipe') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" inset="top bottom" wire:click="editar({{ $p->id }})" :aria-label="__('Editar')" />
                        <flux:button size="sm" variant="ghost" icon="trash" inset="top bottom" wire:click="excluir({{ $p->id }})" wire:confirm="{{ __('Excluir o ponto :nome?', ['nome' => $p->nome]) }}" :aria-label="__('Excluir')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-8 text-center">{{ __('Nenhum ponto de acesso cadastrado.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="ponto" class="w-full max-w-lg">
        <form wire:submit="salvar" class="space-y-6">
            <flux:heading size="lg">{{ $pontoId ? __('Editar ponto de acesso') : __('Novo ponto de acesso') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><flux:input wire:model="nome" :label="__('Nome')" :placeholder="__('Ex.: Portão social, Garagem')" required /></div>
                <flux:select wire:model="tipo" :label="__('Tipo')">
                    @foreach (TipoPontoAcesso::cases() as $t)
                        <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="ordem" type="number" min="0" :label="__('Ordem de exibição')" />
                <flux:select wire:model="dispositivo_id" :label="__('Dispositivo')">
                    @foreach ($this->dispositivos as $d)
                        <flux:select.option :value="$d->id">{{ $d->nome }} ({{ trans_choice(':count canal|:count canais', $d->canais) }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="canal" type="number" min="1" :label="__('Canal (relé)')" />
                <flux:select wire:model.live="modo_acionamento" :label="__('Acionamento')">
                    @foreach (ModoAcionamento::cases() as $m)
                        <flux:select.option :value="$m->value">{{ $m->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($modo_acionamento === 'pulso')
                    <flux:input wire:model="tempo_pulso_ms" type="number" min="100" step="100" :label="__('Tempo do pulso (ms)')" :description:trailing="__('1000 = 1 segundo')" />
                @endif
                <div class="sm:col-span-2 space-y-3">
                    <flux:switch wire:model="liberado_moradores" :label="__('Moradores podem abrir')" :description="__('Se desligado, apenas operadores, porteiros e síndico abrem.')" />
                    <flux:switch wire:model="liberado_visitantes" :label="__('Visitantes podem abrir (link do morador)')" :description="__('Só para acionamento por pulso. Deixe desligado em garagens, se preferir.')" />
                    <flux:switch wire:model="ativo" :label="__('Ativo')" />
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Salvar') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
