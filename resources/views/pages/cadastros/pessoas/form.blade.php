<?php

use App\Enums\Permissao;
use App\Enums\TipoPessoa;
use App\Enums\VinculoUnidade;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Pessoa;
use App\Models\Unidade;
use App\Rules\Cpf;
use App\Support\Documento;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use OperaNoCondominioAtual;

    #[Locked]
    public ?int $pessoaId = null;

    public string $nome = '';
    public string $tipo = 'morador';
    public string $cpf = '';
    public string $rg = '';
    public string $data_nascimento = '';
    public string $telefone = '';
    public string $email = '';
    public bool $ativo = true;
    public string $observacoes = '';

    /** @var list<array{unidade_id: string, vinculo: string, responsavel: bool}> */
    public array $vinculos = [];

    public function mount(?Pessoa $pessoa = null): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);

        if ($pessoa?->exists) {
            $this->garantirDoCondominio($pessoa);
            $this->pessoaId = $pessoa->id;
            $this->fill(collect($pessoa->only(['nome', 'cpf', 'rg', 'telefone', 'email', 'observacoes']))->map(fn ($v) => (string) $v)->all());
            $this->tipo = $pessoa->tipo->value;
            $this->data_nascimento = (string) $pessoa->data_nascimento?->format('Y-m-d');
            $this->ativo = $pessoa->ativo;
            $this->vinculos = $pessoa->unidades->map(fn (Unidade $u) => [
                'unidade_id' => (string) $u->id,
                'vinculo' => $u->pivot->vinculo,
                'responsavel' => (bool) $u->pivot->responsavel,
            ])->values()->all();
        } else {
            $this->vinculos = [['unidade_id' => '', 'vinculo' => VinculoUnidade::Proprietario->value, 'responsavel' => true]];
        }
    }

    public function render()
    {
        return $this->view()->title($this->pessoaId ? __('Editar pessoa') : __('Nova pessoa'));
    }

    #[Computed]
    public function unidades()
    {
        return Unidade::query()->doCondominio($this->condominio())->ordenadas()->with('bloco')->get();
    }

    #[Computed]
    public function usuario()
    {
        return $this->pessoaId ? Pessoa::find($this->pessoaId)?->usuario : null;
    }

    public function adicionarVinculo(): void
    {
        $this->vinculos[] = ['unidade_id' => '', 'vinculo' => VinculoUnidade::Proprietario->value, 'responsavel' => false];
    }

    public function removerVinculo(int $indice): void
    {
        unset($this->vinculos[$indice]);
        $this->vinculos = array_values($this->vinculos);
    }

    public function salvar(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
        $condominioId = $this->condominio()->id;

        $this->cpf = (string) Documento::formatarCpf($this->cpf);
        $this->telefone = (string) Documento::formatarTelefone($this->telefone);
        $this->vinculos = array_values(array_filter($this->vinculos, fn ($v) => $v['unidade_id'] !== ''));

        $dados = $this->validate([
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::enum(TipoPessoa::class)],
            'cpf' => ['nullable', new Cpf, Rule::unique('pessoas', 'cpf')->where('condominio_id', $condominioId)->ignore($this->pessoaId)],
            'rg' => ['nullable', 'string', 'max:20'],
            'data_nascimento' => ['nullable', 'date', 'before:today'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'ativo' => ['boolean'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'vinculos' => ['array'],
            'vinculos.*.unidade_id' => ['required', 'distinct', Rule::exists('unidades', 'id')->where('condominio_id', $condominioId)],
            'vinculos.*.vinculo' => ['required', Rule::enum(VinculoUnidade::class)],
            'vinculos.*.responsavel' => ['boolean'],
        ], attributes: [
            'cpf' => 'CPF', 'rg' => 'RG', 'data_nascimento' => 'data de nascimento',
            'vinculos.*.unidade_id' => 'unidade', 'vinculos.*.vinculo' => 'vínculo',
        ], messages: [
            'vinculos.*.unidade_id.distinct' => __('Esta unidade foi adicionada mais de uma vez.'),
        ]);

        $vinculos = collect($dados['vinculos'])->mapWithKeys(fn ($v) => [
            (int) $v['unidade_id'] => ['vinculo' => $v['vinculo'], 'responsavel' => (bool) $v['responsavel']],
        ])->all();
        unset($dados['vinculos']);
        $dados = collect($dados)->map(fn ($v) => $v === '' ? null : $v)->all();

        if ($this->pessoaId) {
            $pessoa = Pessoa::query()->doCondominio($condominioId)->findOrFail($this->pessoaId);
            $pessoa->update($dados);
        } else {
            $pessoa = $this->condominio()->pessoas()->create($dados);
        }

        $pessoa->unidades()->sync($vinculos);

        Flux::toast(variant: 'success', text: __('Pessoa salva.'));
        $this->redirectRoute('pessoas.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-cabecalho-pagina :titulo="$pessoaId ? __('Editar pessoa') : __('Nova pessoa')" :subtitulo="$pessoaId ? $nome : null">
        <x-slot:acoes>
            <flux:button variant="ghost" icon="arrow-left" :href="route('pessoas.index')" wire:navigate>{{ __('Voltar') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <form wire:submit="salvar" class="space-y-8">
        <flux:fieldset>
            <flux:legend>{{ __('Dados pessoais') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><flux:input wire:model="nome" :label="__('Nome completo')" required /></div>
                <flux:select wire:model="tipo" :label="__('Tipo')">
                    @foreach (TipoPessoa::cases() as $t)
                        <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="data_nascimento" type="date" :label="__('Data de nascimento')" />
                <flux:input wire:model="cpf" :label="__('CPF')" mask="999.999.999-99" placeholder="000.000.000-00" />
                <flux:input wire:model="rg" :label="__('RG')" />
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Contato') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="telefone" :label="__('Celular / telefone')" mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-99999'" placeholder="(00) 00000-0000" />
                <flux:input wire:model="email" type="email" :label="__('E-mail')" />
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Unidades') }}</flux:legend>
            <flux:description class="mb-4">{{ __('Em quais unidades esta pessoa mora ou trabalha.') }}</flux:description>

            @if ($this->unidades->isEmpty())
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.text>
                        {{ __('Nenhuma unidade cadastrada ainda.') }}
                        <flux:callout.link :href="route('unidades.index')" wire:navigate>{{ __('Cadastrar unidades') }}</flux:callout.link>
                    </flux:callout.text>
                </flux:callout>
            @else
                <div class="space-y-3">
                    @foreach ($vinculos as $i => $vinculo)
                        <div class="grid gap-3 rounded-lg border border-zinc-200 p-3 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end dark:border-zinc-700" wire:key="vinculo-{{ $i }}">
                            <flux:select wire:model="vinculos.{{ $i }}.unidade_id" :label="__('Unidade')">
                                <flux:select.option value="">{{ __('Selecione…') }}</flux:select.option>
                                @foreach ($this->unidades as $u)
                                    <flux:select.option :value="$u->id">{{ $u->identificacao }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:select wire:model="vinculos.{{ $i }}.vinculo" :label="__('Vínculo')">
                                @foreach (VinculoUnidade::cases() as $v)
                                    <flux:select.option :value="$v->value">{{ $v->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <div class="pb-2"><flux:checkbox wire:model="vinculos.{{ $i }}.responsavel" :label="__('Responsável')" /></div>
                            <flux:button variant="ghost" icon="x-mark" wire:click="removerVinculo({{ $i }})" :aria-label="__('Remover')" />
                        </div>
                    @endforeach

                    <flux:button size="sm" icon="plus" wire:click="adicionarVinculo">{{ __('Adicionar unidade') }}</flux:button>
                </div>
            @endif
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Outros') }}</flux:legend>
            <div class="space-y-4">
                <flux:textarea wire:model="observacoes" :label="__('Observações')" rows="3" />
                <flux:switch wire:model="ativo" :label="__('Cadastro ativo')" :description="__('Pessoas inativas não aparecem para abertura nem para autorizações.')" />
                @if ($this->usuario)
                    <flux:callout icon="key" variant="secondary">
                        <flux:callout.text>{{ __('Esta pessoa tem acesso ao sistema com o login :email.', ['email' => $this->usuario->email]) }}</flux:callout.text>
                    </flux:callout>
                @endif
            </div>
        </flux:fieldset>

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="route('pessoas.index')" wire:navigate>{{ __('Cancelar') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="check">{{ __('Salvar') }}</flux:button>
        </div>
    </form>
</section>
