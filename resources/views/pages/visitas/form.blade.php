<?php

use App\Enums\Permissao;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Pessoa;
use App\Models\Visita;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Autorizar visitante')] class extends Component {
    use OperaNoCondominioAtual;

    public string $nome = '';
    public string $telefone = '';
    public string $documento = '';
    public string $quando = 'hoje';
    public string $inicio = '';
    public string $fim = '';
    public int $usos = 1;
    public string $observacao = '';

    /** @var list<string> */
    public array $pontosEscolhidos = [];

    public function mount(): void
    {
        $this->exigirPermissao(Permissao::VisitasAutorizar);
        $agora = now()->timezone($this->condominio()->fuso_horario);
        $this->inicio = $agora->format('Y-m-d\TH:i');
        $this->fim = $agora->copy()->endOfDay()->format('Y-m-d\TH:i');
        $this->pontosEscolhidos = $this->pontos->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    #[Computed]
    public function pontos()
    {
        return $this->condominio()->pontosAcesso()
            ->where('ativo', true)->where('liberado_visitantes', true)->where('modo_acionamento', 'pulso')
            ->orderBy('ordem')->orderBy('nome')->get();
    }

    public function salvar(): void
    {
        $this->exigirPermissao(Permissao::VisitasAutorizar);
        $fuso = $this->condominio()->fuso_horario;

        $dados = $this->validate([
            'nome' => ['required', 'string', 'max:120'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'documento' => ['nullable', 'string', 'max:30'],
            'quando' => ['required', Rule::in(['hoje', '24h', 'periodo'])],
            'inicio' => [Rule::requiredIf($this->quando === 'periodo'), 'nullable', 'date'],
            'fim' => [Rule::requiredIf($this->quando === 'periodo'), 'nullable', 'date', 'after:inicio'],
            'usos' => ['required', 'integer', 'between:1,50'],
            'observacao' => ['nullable', 'string', 'max:500'],
            'pontosEscolhidos' => ['required', 'array', 'min:1'],
            'pontosEscolhidos.*' => [Rule::in($this->pontos->pluck('id')->map(fn ($id) => (string) $id)->all())],
        ], [
            'pontosEscolhidos.required' => __('Escolha pelo menos um portão.'),
        ], ['fim' => 'término', 'inicio' => 'início', 'usos' => 'número de entradas']);

        [$de, $ate] = match ($this->quando) {
            'hoje' => [now(), now()->timezone($fuso)->endOfDay()->utc()],
            '24h' => [now(), now()->addDay()],
            default => [Carbon::parse($this->inicio, $fuso)->utc(), Carbon::parse($this->fim, $fuso)->utc()],
        };

        abort_if($ate->diffInDays($de, true) > 31, 422, __('O período máximo é de 31 dias.'));

        // A unidade de quem autoriza (pela pessoa vinculada ao login).
        $unidadeId = Pessoa::query()->doCondominio($this->condominio())->where('user_id', auth()->id())
            ->first()?->unidades()->value('unidades.id');

        $visita = new Visita([
            'nome' => trim($dados['nome']),
            'telefone' => $dados['telefone'] ?: null,
            'documento' => $dados['documento'] ?: null,
            'observacao' => $dados['observacao'] ?: null,
            'valido_de' => $de,
            'valido_ate' => $ate,
            'usos_maximos' => $dados['usos'],
        ]);
        $visita->forceFill(['condominio_id' => $this->condominio()->id, 'user_id' => auth()->id(), 'unidade_id' => $unidadeId]);
        $visita->gerarToken();
        $visita->save();
        $visita->pontos()->sync(array_map('intval', $dados['pontosEscolhidos']));

        activity('visita')->causedBy(auth()->user())->performedOn($visita)
            ->withProperties(['condominio_id' => $visita->condominio_id, 'nome' => $visita->nome])->log('Visitante autorizado');

        $this->redirectRoute('visitas.ver', $visita, navigate: true);
    }
}; ?>

<section class="w-full max-w-xl">
    <x-cabecalho-pagina :titulo="__('Autorizar visitante')" :subtitulo="__('Você vai receber um link para enviar ao visitante.')">
        <x-slot:acoes>
            <flux:button variant="ghost" icon="arrow-left" :href="route('visitas.index')" wire:navigate>{{ __('Voltar') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    @if ($this->pontos->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('Nenhum portão liberado para visitantes')">
            <flux:callout.text>{{ __('A administração precisa liberar ao menos um portão para visitantes. Fale com o síndico ou a portaria.') }}</flux:callout.text>
        </flux:callout>
    @else
        <form wire:submit="salvar" class="space-y-6">
            <flux:input wire:model="nome" :label="__('Nome do visitante')" required autofocus autocomplete="off" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="telefone" :label="__('Celular (opcional)')" mask:dynamic="$input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-99999'" inputmode="tel" />
                <flux:input wire:model="documento" :label="__('Documento (opcional)')" />
            </div>

            <flux:radio.group wire:model.live="quando" :label="__('Quando')" variant="segmented">
                <flux:radio value="hoje" :label="__('Hoje')" />
                <flux:radio value="24h" :label="__('Próximas 24 h')" />
                <flux:radio value="periodo" :label="__('Escolher')" />
            </flux:radio.group>

            @if ($quando === 'periodo')
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="inicio" type="datetime-local" :label="__('De')" />
                    <flux:input wire:model="fim" type="datetime-local" :label="__('Até')" />
                </div>
            @endif

            <flux:select wire:model="usos" :label="__('Quantas entradas')" :description:trailing="__('Cada vez que o visitante abre um portão conta uma entrada.')">
                @foreach ([1, 2, 3, 5, 10, 20, 50] as $n)
                    <flux:select.option :value="$n">{{ trans_choice(':count entrada|:count entradas', $n) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:checkbox.group wire:model="pontosEscolhidos" :label="__('Portões que ele poderá abrir')">
                @foreach ($this->pontos as $p)
                    <flux:checkbox :value="(string) $p->id" :label="$p->nome" />
                @endforeach
            </flux:checkbox.group>

            <flux:textarea wire:model="observacao" :label="__('Observação para a portaria (opcional)')" rows="2" :placeholder="__('Ex.: entregador, diarista, técnico da internet')" />

            <flux:button type="submit" variant="primary" icon="qr-code" class="w-full">{{ __('Gerar link do visitante') }}</flux:button>
        </form>
    @endif
</section>
