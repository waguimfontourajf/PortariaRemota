<?php

use App\Enums\Permissao;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\HorarioAcesso;
use App\Models\Pessoa;
use App\Support\SincronizacaoFacial;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Horários de acesso')] class extends Component {
    use OperaNoCondominioAtual;

    #[Locked]
    public ?int $horarioId = null;

    public string $nome = '';

    /** @var list<string> */
    public array $dias = ['1', '2', '3', '4', '5'];

    public string $inicio = '07:00';
    public string $fim = '18:00';

    public function mount(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
    }

    #[Computed]
    public function horarios()
    {
        return $this->condominio()->horariosAcesso()->withCount('pessoas')->orderBy('nome')->get();
    }

    public function novo(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
        $this->reset('horarioId', 'nome', 'dias', 'inicio', 'fim');
        $this->resetValidation();
        Flux::modal('horario')->show();
    }

    public function editar(int $id): void
    {
        $horario = $this->buscar($id);
        $this->horarioId = $horario->id;
        $this->nome = $horario->nome;
        $this->dias = array_map('strval', $horario->dias);
        $this->inicio = substr($horario->inicio, 0, 5);
        $this->fim = substr($horario->fim, 0, 5);
        $this->resetValidation();
        Flux::modal('horario')->show();
    }

    public function salvar(): void
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);
        $condominio = $this->condominio();
        $this->nome = trim($this->nome);

        $dados = $this->validate([
            'nome' => ['required', 'string', 'max:60', Rule::unique('horarios_acesso')->where('condominio_id', $condominio->id)->ignore($this->horarioId)],
            'dias' => ['required', 'array', 'min:1'],
            'dias.*' => ['integer', 'between:0,6'],
            'inicio' => ['required', 'date_format:H:i'],
            'fim' => ['required', 'date_format:H:i', 'after:inicio'],
        ], attributes: ['inicio' => 'início'], messages: [
            'dias.required' => __('Escolha pelo menos um dia.'),
            'fim.after' => __('O fim precisa ser depois do início (horário que passa da meia-noite não é aceito).'),
        ]);

        $dados['dias'] = collect($dados['dias'])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();
        $dados['inicio'] .= ':00';
        $dados['fim'] .= ':59';

        if ($this->horarioId) {
            $horario = $this->buscar($this->horarioId);
            $horario->update($dados);
        } else {
            $numeros = $condominio->horariosAcesso()->pluck('numero');
            $numero = collect(range(1, HorarioAcesso::MAXIMO_POR_CONDOMINIO))->first(fn ($n) => ! $numeros->contains($n));

            if ($numero === null) {
                Flux::toast(variant: 'danger', text: __('Limite de :n horários por condomínio.', ['n' => HorarioAcesso::MAXIMO_POR_CONDOMINIO]));

                return;
            }

            $horario = new HorarioAcesso($dados);
            $horario->condominio_id = $condominio->id;
            $horario->numero = $numero;
            $horario->save();
        }

        Flux::modal('horario')->close();
        unset($this->horarios);

        // Quem usa este horário precisa ser atualizado nas leitoras.
        $erros = $this->atualizarLeitoras($horario);
        Flux::toast(variant: $erros ? 'warning' : 'success', duration: $erros ? 15000 : 5000,
            text: __('Horário salvo.').($erros ? ' '.__('Leitoras com erro: :e', ['e' => $erros]) : ''));
    }

    public function excluir(int $id): void
    {
        $horario = $this->buscar($id);
        $pessoas = $horario->pessoas()->pluck('id');
        $horario->delete(); // as pessoas ficam sem restrição de horário (nullOnDelete)

        foreach (Pessoa::whereKey($pessoas)->get() as $pessoa) {
            app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa);
        }

        Flux::toast(variant: 'success', text: __('Horário excluído. As pessoas que o usavam ficaram sem restrição de horário.'));
        unset($this->horarios);
    }

    private function atualizarLeitoras(HorarioAcesso $horario): string
    {
        $erros = [];

        foreach ($horario->pessoas()->get() as $pessoa) {
            foreach (app(SincronizacaoFacial::class)->sincronizarPessoa($pessoa) as $r) {
                if (! $r['sucesso']) {
                    $erros[$r['leitora']] = $r['leitora'];
                }
            }
        }

        return implode(', ', $erros);
    }

    private function buscar(int $id): HorarioAcesso
    {
        $this->exigirPermissao(Permissao::CadastrosGerenciar);

        return $this->condominio()->horariosAcesso()->findOrFail($id);
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-cabecalho-pagina :titulo="__('Horários de acesso')" :subtitulo="__('Faixas de dias e horas em que a pessoa pode entrar pela leitora facial. Ex.: diaristas de segunda a sexta, das 7h às 18h.')">
        <x-slot:acoes>
            <flux:button variant="primary" icon="plus" wire:click="novo">{{ __('Novo horário') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Nome') }}</flux:table.column>
            <flux:table.column>{{ __('Quando') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Pessoas') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->horarios as $horario)
                <flux:table.row :key="$horario->id">
                    <flux:table.cell variant="strong">{{ $horario->nome }}</flux:table.cell>
                    <flux:table.cell>{{ $horario->descricao() }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $horario->pessoas_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" inset="top bottom" wire:click="editar({{ $horario->id }})" :aria-label="__('Editar')" />
                        <flux:button size="sm" variant="ghost" icon="trash" inset="top bottom" wire:click="excluir({{ $horario->id }})" wire:confirm="{{ __('Excluir o horário :nome? Quem o usa fica sem restrição de horário.', ['nome' => $horario->nome]) }}" :aria-label="__('Excluir')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="py-8 text-center">{{ __('Nenhum horário cadastrado. Sem horário, a pessoa entra a qualquer hora.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="horario" class="w-full max-w-md">
        <form wire:submit="salvar" class="space-y-6">
            <flux:heading size="lg">{{ $horarioId ? __('Editar horário') : __('Novo horário') }}</flux:heading>
            <flux:input wire:model="nome" :label="__('Nome')" :placeholder="__('Ex.: Diaristas, Comercial, Fim de semana')" required />
            <flux:checkbox.group wire:model="dias" :label="__('Dias')">
                <div class="flex flex-wrap gap-x-4 gap-y-2">
                    @foreach (HorarioAcesso::DIAS as $numero => $dia)
                        <flux:checkbox :value="(string) $numero" :label="$dia" />
                    @endforeach
                </div>
            </flux:checkbox.group>
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="inicio" type="time" :label="__('Das')" required />
                <flux:input wire:model="fim" type="time" :label="__('Até')" required />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancelar') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Salvar') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
