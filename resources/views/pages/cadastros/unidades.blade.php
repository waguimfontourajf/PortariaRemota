<?php

use App\Enums\Permissao;
use App\Enums\TipoUnidade;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Unidade;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Unidades')] class extends Component {
    use OperaNoCondominioAtual, WithPagination;

    #[Url(except: '')]
    public string $bloco = '';

    #[Url(except: '')]
    public string $busca = '';

    // Formulário de unidade
    #[Locked]
    public ?int $unidadeId = null;
    public string $bloco_id = '';
    public string $numero = '';
    public string $andar = '';
    public string $tipo = 'apartamento';
    public string $observacoes = '';

    // Geração em lote
    public string $lote_bloco_id = '';
    public string $lote_tipo = 'apartamento';
    public int $lote_andar_inicial = 1;
    public int $lote_andar_final = 10;
    public int $lote_por_andar = 4;
    public int $lote_inicio = 1;
    public int $lote_fim = 20;

    public function mount(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
    }

    public function updating(string $campo): void
    {
        if (in_array($campo, ['bloco', 'busca'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function blocos()
    {
        return $this->condominio()->blocos()->orderBy('ordem')->orderBy('nome')->get();
    }

    #[Computed]
    public function unidades()
    {
        return Unidade::query()
            ->doCondominio($this->condominio())
            ->ordenadas()
            ->with('bloco')
            ->withCount('pessoas')
            ->when($this->bloco === 'sem', fn ($q) => $q->whereNull('unidades.bloco_id'))
            ->when(ctype_digit($this->bloco), fn ($q) => $q->where('unidades.bloco_id', (int) $this->bloco))
            ->when($this->busca !== '', fn ($q) => $q->where('unidades.numero', 'like', "%{$this->busca}%"))
            ->paginate(50);
    }

    public function nova(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
        $this->reset('unidadeId', 'numero', 'andar', 'observacoes');
        $this->bloco_id = ctype_digit($this->bloco) ? $this->bloco : '';
        $this->tipo = $this->blocos->isEmpty() ? TipoUnidade::Casa->value : TipoUnidade::Apartamento->value;
        $this->resetValidation();
        Flux::modal('unidade')->show();
    }

    public function editar(int $id): void
    {
        $unidade = $this->buscar($id);
        $this->unidadeId = $unidade->id;
        $this->bloco_id = (string) $unidade->bloco_id;
        $this->numero = $unidade->numero;
        $this->andar = (string) $unidade->andar;
        $this->tipo = $unidade->tipo->value;
        $this->observacoes = (string) $unidade->observacoes;
        $this->resetValidation();
        Flux::modal('unidade')->show();
    }

    public function salvar(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
        $condominioId = $this->condominio()->id;
        $this->numero = trim($this->numero);

        $dados = $this->validate([
            'bloco_id' => ['nullable', Rule::exists('blocos', 'id')->where('condominio_id', $condominioId)],
            'numero' => [
                'required', 'string', 'max:20',
                Rule::unique('unidades')
                    ->where('condominio_id', $condominioId)
                    ->where(fn ($q) => $this->bloco_id ? $q->where('bloco_id', $this->bloco_id) : $q->whereNull('bloco_id'))
                    ->ignore($this->unidadeId),
            ],
            'andar' => ['nullable', 'string', 'max:10'],
            'tipo' => ['required', Rule::enum(TipoUnidade::class)],
            'observacoes' => ['nullable', 'string', 'max:1000'],
        ], attributes: ['bloco_id' => 'bloco', 'numero' => 'número']);

        $dados = collect($dados)->map(fn ($v) => $v === '' ? null : $v)->all();

        if ($this->unidadeId) {
            $this->buscar($this->unidadeId)->update($dados);
        } else {
            $this->condominio()->unidades()->create($dados);
        }

        Flux::modal('unidade')->close();
        Flux::toast(variant: 'success', text: __('Unidade salva.'));
        unset($this->unidades);
    }

    public function excluir(int $id): void
    {
        $unidade = $this->buscar($id);

        if ($unidade->pessoas()->exists()) {
            Flux::toast(variant: 'danger', text: __('Há pessoas vinculadas a esta unidade. Remova os vínculos antes de excluir.'));

            return;
        }

        $unidade->delete();
        Flux::toast(variant: 'success', text: __('Unidade excluída.'));
        unset($this->unidades);
    }

    public function abrirLote(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
        $this->lote_bloco_id = ctype_digit($this->bloco) ? $this->bloco : (string) ($this->blocos->first()?->id ?? '');
        $this->lote_tipo = $this->blocos->isEmpty() ? TipoUnidade::Casa->value : TipoUnidade::Apartamento->value;
        $this->resetValidation();
        Flux::modal('lote')->show();
    }

    /**
     * Prévia dos números que serão gerados.
     *
     * @return list<array{numero: string, andar: ?string}>
     */
    public function numerosDoLote(): array
    {
        $numeros = [];

        if ($this->lote_tipo === TipoUnidade::Apartamento->value) {
            $ini = max(0, $this->lote_andar_inicial);
            $fim = min(200, $this->lote_andar_final);
            $porAndar = min(99, max(1, $this->lote_por_andar));

            for ($andar = $ini; $andar <= $fim; $andar++) {
                for ($i = 1; $i <= $porAndar; $i++) {
                    $numeros[] = ['numero' => $andar.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'andar' => (string) $andar];
                }
            }
        } else {
            for ($n = max(1, $this->lote_inicio); $n <= min(9999, $this->lote_fim); $n++) {
                $numeros[] = ['numero' => (string) $n, 'andar' => null];
            }
        }

        return array_slice($numeros, 0, 2000);
    }

    public function gerarLote(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
        $condominioId = $this->condominio()->id;

        $this->validate([
            'lote_bloco_id' => ['nullable', Rule::exists('blocos', 'id')->where('condominio_id', $condominioId)],
            'lote_tipo' => ['required', Rule::enum(TipoUnidade::class)],
            'lote_andar_inicial' => ['integer', 'min:0', 'max:200'],
            'lote_andar_final' => ['integer', 'gte:lote_andar_inicial', 'max:200'],
            'lote_por_andar' => ['integer', 'min:1', 'max:99'],
            'lote_inicio' => ['integer', 'min:1', 'max:9999'],
            'lote_fim' => ['integer', 'gte:lote_inicio', 'max:9999'],
        ], attributes: [
            'lote_andar_final' => 'andar final', 'lote_andar_inicial' => 'andar inicial',
            'lote_fim' => 'número final', 'lote_inicio' => 'número inicial', 'lote_bloco_id' => 'bloco',
        ]);

        $blocoId = $this->lote_bloco_id !== '' ? (int) $this->lote_bloco_id : null;

        $existentes = Unidade::query()->doCondominio($condominioId)
            ->where(fn ($q) => $blocoId ? $q->where('bloco_id', $blocoId) : $q->whereNull('bloco_id'))
            ->pluck('numero')->flip();

        $criadas = 0;

        foreach ($this->numerosDoLote() as $item) {
            if ($existentes->has($item['numero'])) {
                continue;
            }

            $this->condominio()->unidades()->create([
                'bloco_id' => $blocoId,
                'numero' => $item['numero'],
                'andar' => $item['andar'],
                'tipo' => $this->lote_tipo,
            ]);
            $criadas++;
        }

        Flux::modal('lote')->close();
        Flux::toast(variant: 'success', text: trans_choice('{0} Nenhuma unidade nova (todas já existiam).|{1} 1 unidade criada.|[2,*] :count unidades criadas.', $criadas));
        unset($this->unidades);
    }

    private function buscar(int $id): Unidade
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);

        return Unidade::query()->doCondominio($this->condominio())->findOrFail($id);
    }
}; ?>

<section class="w-full">
    <x-cabecalho-pagina :titulo="__('Unidades')" :subtitulo="__('Apartamentos, casas e salas do condomínio.')">
        <x-slot:acoes>
            <flux:button icon="squares-plus" wire:click="abrirLote">{{ __('Gerar em lote') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="nova">{{ __('Nova unidade') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <flux:select wire:model.live="bloco" class="sm:max-w-56">
            <flux:select.option value="">{{ __('Todos os blocos') }}</flux:select.option>
            @foreach ($this->blocos as $b)
                <flux:select.option :value="$b->id">{{ $b->nome }}</flux:select.option>
            @endforeach
            <flux:select.option value="sem">{{ __('Sem bloco') }}</flux:select.option>
        </flux:select>
        <flux:input wire:model.live.debounce.400ms="busca" icon="magnifying-glass" :placeholder="__('Buscar número')" class="sm:max-w-56" />
    </div>

    <flux:table :paginate="$this->unidades">
        <flux:table.columns>
            <flux:table.column>{{ __('Unidade') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden">{{ __('Andar') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden">{{ __('Tipo') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Pessoas') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->unidades as $unidade)
                <flux:table.row :key="$unidade->id">
                    <flux:table.cell variant="strong">{{ $unidade->identificacao }}</flux:table.cell>
                    <flux:table.cell class="max-sm:hidden">{{ $unidade->andar ?? '—' }}</flux:table.cell>
                    <flux:table.cell class="max-sm:hidden">{{ $unidade->tipo->label() }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $unidade->pessoas_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" inset="top bottom" wire:click="editar({{ $unidade->id }})" :aria-label="__('Editar')" />
                        <flux:button size="sm" variant="ghost" icon="trash" inset="top bottom" wire:click="excluir({{ $unidade->id }})" wire:confirm="{{ __('Excluir a unidade :u?', ['u' => $unidade->identificacao]) }}" :aria-label="__('Excluir')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-8 text-center">{{ __('Nenhuma unidade encontrada.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="unidade" class="w-full max-w-lg">
        <form wire:submit="salvar" class="space-y-6">
            <flux:heading size="lg">{{ $unidadeId ? __('Editar unidade') : __('Nova unidade') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="bloco_id" :label="__('Bloco')">
                    <flux:select.option value="">{{ __('Sem bloco') }}</flux:select.option>
                    @foreach ($this->blocos as $b)
                        <flux:select.option :value="$b->id">{{ $b->nome }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="tipo" :label="__('Tipo')">
                    @foreach (TipoUnidade::cases() as $t)
                        <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="numero" :label="__('Número')" required />
                <flux:input wire:model="andar" :label="__('Andar')" />
                <div class="sm:col-span-2"><flux:textarea wire:model="observacoes" :label="__('Observações')" rows="2" /></div>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Salvar') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="lote" class="w-full max-w-lg">
        <form wire:submit="gerarLote" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Gerar unidades em lote') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Números que já existem são ignorados.') }}</flux:text>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="lote_bloco_id" :label="__('Bloco')">
                    <flux:select.option value="">{{ __('Sem bloco') }}</flux:select.option>
                    @foreach ($this->blocos as $b)
                        <flux:select.option :value="$b->id">{{ $b->nome }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="lote_tipo" :label="__('Tipo')">
                    @foreach (TipoUnidade::cases() as $t)
                        <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($lote_tipo === 'apartamento')
                    <flux:input wire:model.live.debounce.300ms="lote_andar_inicial" type="number" min="0" :label="__('Andar inicial')" />
                    <flux:input wire:model.live.debounce.300ms="lote_andar_final" type="number" min="0" :label="__('Andar final')" />
                    <div class="sm:col-span-2"><flux:input wire:model.live.debounce.300ms="lote_por_andar" type="number" min="1" :label="__('Unidades por andar')" /></div>
                @else
                    <flux:input wire:model.live.debounce.300ms="lote_inicio" type="number" min="1" :label="__('Do número')" />
                    <flux:input wire:model.live.debounce.300ms="lote_fim" type="number" min="1" :label="__('Até o número')" />
                @endif
            </div>

            @php($previa = $this->numerosDoLote())
            <flux:callout icon="information-circle" variant="secondary">
                <flux:callout.text>
                    {{ trans_choice('{0} Nenhuma unidade.|{1} 1 unidade:|[2,*] :count unidades:', count($previa)) }}
                    {{ collect($previa)->pluck('numero')->take(8)->join(', ') }}{{ count($previa) > 8 ? ' … '.last($previa)['numero'] : '' }}
                </flux:callout.text>
            </flux:callout>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Gerar') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
