<?php

use App\Enums\ModoOperacao;
use App\Enums\Permissao;
use App\Models\Condominio;
use App\Rules\Cnpj;
use App\Support\Documento;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public ?int $condominioId = null;

    public string $nome = '';
    public string $cnpj = '';
    public string $telefone = '';
    public string $email = '';
    public string $cep = '';
    public string $logradouro = '';
    public string $numero = '';
    public string $complemento = '';
    public string $bairro = '';
    public string $cidade = '';
    public string $uf = '';
    public string $modo_operacao = 'direto';
    public bool $ativo = true;
    public string $observacoes = '';
    public string $telegram_chat_id = '';

    public function mount(?Condominio $condominio = null): void
    {
        if ($condominio?->exists) {
            $this->autorizar($condominio);
            $this->condominioId = $condominio->id;
            $this->fill(collect($condominio->only([
                'nome', 'cnpj', 'telefone', 'email', 'cep', 'logradouro', 'numero',
                'complemento', 'bairro', 'cidade', 'uf', 'observacoes', 'telegram_chat_id',
            ]))->map(fn ($v) => (string) $v)->all());
            $this->modo_operacao = $condominio->modo_operacao->value;
            $this->ativo = $condominio->ativo;
        } else {
            $this->autorizar(null);
        }
    }

    public function render()
    {
        return $this->view()->title($this->condominioId ? __('Editar condomínio') : __('Novo condomínio'));
    }

    public function podeAlterarAdministrativo(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function salvar(): void
    {
        $condominio = $this->condominioId ? Condominio::findOrFail($this->condominioId) : null;
        $this->autorizar($condominio);

        $this->cnpj = (string) Documento::formatarCnpj($this->cnpj);
        $this->telefone = (string) Documento::formatarTelefone($this->telefone);
        $this->cep = (string) Documento::formatarCep($this->cep);
        $this->uf = mb_strtoupper($this->uf);

        $dados = $this->validate([
            'nome' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', new Cnpj, Rule::unique('condominios', 'cnpj')->ignore($this->condominioId)],
            'telefone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'cep' => ['nullable', 'regex:/^\d{5}-\d{3}$/'],
            'logradouro' => ['nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:255'],
            'bairro' => ['nullable', 'string', 'max:255'],
            'cidade' => ['nullable', 'string', 'max:255'],
            'uf' => ['nullable', 'string', 'size:2'],
            'modo_operacao' => ['required', Rule::enum(ModoOperacao::class)],
            'ativo' => ['boolean'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'telegram_chat_id' => ['nullable', 'regex:/^-?\d{3,20}$/'],
        ], attributes: [
            'cep' => 'CEP', 'uf' => 'UF', 'cnpj' => 'CNPJ', 'modo_operacao' => 'modo de operação',
        ]);

        $dados = collect($dados)->map(fn ($v) => $v === '' ? null : $v)->all();

        if (! $this->podeAlterarAdministrativo()) {
            unset($dados['modo_operacao'], $dados['ativo'], $dados['telegram_chat_id']);
        }

        if ($condominio) {
            $condominio->update($dados);
            Flux::toast(variant: 'success', text: __('Condomínio atualizado.'));
        } else {
            $condominio = Condominio::create($dados);
            session()->flash('toast_sucesso', __('Condomínio cadastrado.'));
            $this->redirectRoute('condominios.editar', $condominio, navigate: true);
        }
    }

    /**
     * Criar: só super admin. Editar: super admin ou quem tem
     * "gerenciar condomínio" no próprio condomínio (atual).
     */
    private function autorizar(?Condominio $condominio): void
    {
        $usuario = auth()->user();

        if ($usuario?->isSuperAdmin()) {
            return;
        }

        abort_unless(
            $condominio
            && app(\App\Support\CondominioAtual::class)->id() === $condominio->id
            && $usuario?->can(Permissao::CondominioGerenciar->value),
            403,
        );
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-cabecalho-pagina
        :titulo="$condominioId ? __('Dados do condomínio') : __('Novo condomínio')"
        :subtitulo="$condominioId ? $nome : __('Cadastre um condomínio atendido pela plataforma.')"
    >
        @if (auth()->user()->isSuperAdmin())
            <x-slot:acoes>
                <flux:button variant="ghost" icon="arrow-left" :href="route('condominios.index')" wire:navigate>{{ __('Voltar') }}</flux:button>
            </x-slot:acoes>
        @endif
    </x-cabecalho-pagina>

    @if (session('toast_sucesso'))
        <flux:callout variant="success" icon="check-circle" class="mb-6" :heading="session('toast_sucesso')" />
    @endif

    <form wire:submit="salvar" class="space-y-8">
        <flux:fieldset>
            <flux:legend>{{ __('Identificação') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><flux:input wire:model="nome" :label="__('Nome')" required /></div>
                <flux:input wire:model="cnpj" :label="__('CNPJ')" mask="99.999.999/9999-99" placeholder="00.000.000/0000-00" />
                <flux:input wire:model="telefone" :label="__('Telefone')" mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-99999'" placeholder="(00) 0000-0000" />
                <div class="sm:col-span-2"><flux:input wire:model="email" :label="__('E-mail')" type="email" /></div>
            </div>
        </flux:fieldset>

        <flux:fieldset
            x-data="{
                buscando: false,
                async buscarCep() {
                    const cep = ($wire.cep || '').replace(/\D/g, '');
                    if (cep.length !== 8) return;
                    this.buscando = true;
                    try {
                        const r = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
                        const d = await r.json();
                        if (! d.erro) {
                            $wire.logradouro = d.logradouro || $wire.logradouro;
                            $wire.bairro = d.bairro || $wire.bairro;
                            $wire.cidade = d.localidade || $wire.cidade;
                            $wire.uf = d.uf || $wire.uf;
                        }
                    } catch (e) {} finally { this.buscando = false; }
                }
            }"
        >
            <flux:legend>{{ __('Endereço') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-6">
                <div class="sm:col-span-2"><flux:input wire:model="cep" :label="__('CEP')" mask="99999-999" placeholder="00000-000" x-on:blur="buscarCep()" :description:trailing="__('Preenche o endereço automaticamente.')" /></div>
                <div class="sm:col-span-4"><flux:input wire:model="logradouro" :label="__('Logradouro')" /></div>
                <div class="sm:col-span-2"><flux:input wire:model="numero" :label="__('Número')" /></div>
                <div class="sm:col-span-4"><flux:input wire:model="complemento" :label="__('Complemento')" /></div>
                <div class="sm:col-span-2"><flux:input wire:model="bairro" :label="__('Bairro')" /></div>
                <div class="sm:col-span-3"><flux:input wire:model="cidade" :label="__('Cidade')" /></div>
                <div class="sm:col-span-1"><flux:input wire:model="uf" :label="__('UF')" maxlength="2" /></div>
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Operação') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="modo_operacao" :label="__('Modo de operação')" :disabled="! $this->podeAlterarAdministrativo()">
                    @foreach (ModoOperacao::cases() as $modo)
                        <flux:select.option :value="$modo->value">{{ $modo->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="flex items-end pb-2">
                    <flux:switch wire:model="ativo" :label="__('Condomínio ativo')" :disabled="! $this->podeAlterarAdministrativo()" />
                </div>
                @if ($this->podeAlterarAdministrativo())
                    <div class="sm:col-span-2"><flux:input wire:model="telegram_chat_id" :label="__('Grupo do Telegram do condomínio (opcional)')" placeholder="-1001234567890" :description:trailing="__('Recebe os alertas de queda só deste condomínio (ex.: grupo com o síndico). O bot precisa estar no grupo. Use Alertas (Telegram) → Procurar conversas para achar o número.')" /></div>
                @endif
                <div class="sm:col-span-2"><flux:textarea wire:model="observacoes" :label="__('Observações')" rows="3" /></div>
            </div>
        </flux:fieldset>

        <div class="flex justify-end gap-2">
            <flux:button type="submit" variant="primary" icon="check">{{ __('Salvar') }}</flux:button>
        </div>
    </form>
</section>
