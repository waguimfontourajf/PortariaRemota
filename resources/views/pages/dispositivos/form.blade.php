<?php

use App\Dispositivos\Campo;
use App\Dispositivos\RegistroDeDrivers;
use App\Enums\Permissao;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Dispositivo;
use App\Models\Pessoa;
use App\Support\SincronizacaoFacial;
use Illuminate\Support\Facades\DB;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    use OperaNoCondominioAtual;

    #[Locked]
    public ?int $dispositivoId = null;

    public string $nome = '';
    public string $driver = 'shelly';
    public string $host = '';
    public string $porta = '';
    public int $canais = 1;
    public bool $ativo = true;
    public string $observacoes = '';

    /** @var array<string, mixed> */
    public array $config = [];

    /** Campos secretos que já têm valor salvo (não são enviados à tela). */
    /** @var list<string> */
    #[Locked]
    public array $segredosSalvos = [];

    public int $canalTeste = 1;

    public ?array $ultimoTeste = null;

    /** Resultado da última sincronização de pessoas (leitoras faciais). */
    public ?array $ultimaSincronizacao = null;

    public function mount(?Dispositivo $dispositivo = null): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);

        if ($dispositivo?->exists) {
            $this->garantirDoCondominio($dispositivo);
            $this->dispositivoId = $dispositivo->id;
            $this->nome = $dispositivo->nome;
            $this->driver = $dispositivo->driver;
            $this->host = (string) $dispositivo->host;
            $this->porta = (string) $dispositivo->porta;
            $this->canais = $dispositivo->canais;
            $this->ativo = $dispositivo->ativo;
            $this->observacoes = (string) $dispositivo->observacoes;

            foreach ($this->campos as $campo) {
                $valor = $dispositivo->config[$campo->nome] ?? $campo->padrao;
                if ($campo->tipo === 'senha') {
                    if (filled($valor)) {
                        $this->segredosSalvos[] = $campo->nome;
                    }
                    $valor = '';
                }
                $this->config[$campo->nome] = $valor;
            }
        } else {
            $this->aplicarPadroes();
        }
    }

    public function render()
    {
        return $this->view()->title($this->dispositivoId ? __('Editar dispositivo') : __('Novo dispositivo'));
    }

    #[Computed]
    public function drivers(): array
    {
        return app(RegistroDeDrivers::class)->reles();
    }

    /**
     * @return class-string<\App\Dispositivos\Contratos\DriverDeRele>
     */
    #[Computed]
    public function classe(): string
    {
        return app(RegistroDeDrivers::class)->classe($this->driver);
    }

    /**
     * @return list<Campo>
     */
    #[Computed]
    public function campos(): array
    {
        return $this->classe::campos();
    }

    public function updatedDriver(): void
    {
        abort_unless(app(RegistroDeDrivers::class)->existe($this->driver), 422);
        unset($this->classe, $this->campos);
        $this->segredosSalvos = [];
        $this->config = [];
        $this->aplicarPadroes();
        $this->resetValidation();
    }

    public function salvar(): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        $dados = $this->validar();
        $existente = $this->dispositivoId ? $this->condominio()->dispositivos()->findOrFail($this->dispositivoId) : null;
        $dados['config'] = $this->configFinal($existente);

        if ($existente) {
            $existente->update($dados);
            Flux::toast(variant: 'success', text: __('Dispositivo salvo.'));
        } else {
            $novo = $this->condominio()->dispositivos()->create($dados);
            Flux::toast(variant: 'success', text: __('Dispositivo cadastrado. Agora teste a comunicação.'));
            $this->redirectRoute('dispositivos.editar', $novo, navigate: true);
        }
    }

    /**
     * Esta tela é de uma leitora facial já salva?
     */
    #[Computed]
    public function ehLeitoraSalva(): bool
    {
        if (! $this->dispositivoId) {
            return false;
        }

        $dispositivo = $this->condominio()->dispositivos()->find($this->dispositivoId);

        return $dispositivo !== null && $dispositivo->driver === $this->driver && app(SincronizacaoFacial::class)->ehLeitora($dispositivo);
    }

    /**
     * Números para o quadro "Pessoas na leitora".
     *
     * @return array{liberadas: int, na_leitora: int, com_erro: int}
     */
    #[Computed]
    public function resumoDaLeitora(): array
    {
        $liberadas = Pessoa::query()->doCondominio($this->condominio())->where('ativo', true)
            ->whereNotNull('foto_path')->whereNotNull('biometria_autorizada_em')->count();
        $registros = DB::table('dispositivo_pessoa')->where('dispositivo_id', $this->dispositivoId);

        return [
            'liberadas' => $liberadas,
            'na_leitora' => (clone $registros)->whereNull('erro')->whereNotNull('sincronizado_em')->count(),
            'com_erro' => (clone $registros)->whereNotNull('erro')->count(),
        ];
    }

    /**
     * Envia para a leitora as pessoas novas/alteradas e remove as que saíram.
     */
    public function sincronizarPessoas(bool $reenviarTodas = false): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        $dispositivo = $this->condominio()->dispositivos()->findOrFail($this->dispositivoId);
        abort_unless(app(SincronizacaoFacial::class)->ehLeitora($dispositivo), 422);

        $inicio = microtime(true);
        $totais = app(SincronizacaoFacial::class)->sincronizarLeitora($dispositivo, $reenviarTodas);

        activity('dispositivo')->causedBy(auth()->user())->performedOn($dispositivo)
            ->withProperties([...$totais, 'erros' => count($totais['erros']), 'condominio_id' => $dispositivo->condominio_id])
            ->log($reenviarTodas ? 'Reenviou todas as pessoas para a leitora' : 'Sincronizou as pessoas da leitora');

        $this->ultimaSincronizacao = [...$totais, 'segundos' => (int) round(microtime(true) - $inicio)];
        unset($this->resumoDaLeitora);
    }

    /**
     * Testa com os dados da tela, mesmo antes de salvar.
     */
    public function testar(string $acao = 'testar'): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        $dados = $this->validar();
        $existente = $this->dispositivoId ? $this->condominio()->dispositivos()->findOrFail($this->dispositivoId) : null;

        $temporario = $existente ? clone $existente : new Dispositivo;
        $temporario->forceFill([...$dados, 'config' => $this->configFinal($existente)]);
        if (! $temporario->id) {
            $temporario->id = 0;
        }

        $canal = max(1, min($this->canais, $this->canalTeste));
        $driver = app(RegistroDeDrivers::class)->rele($this->driver);

        $resultado = match ($acao) {
            'ligar' => $driver->ligar($temporario, $canal),
            'desligar' => $driver->desligar($temporario, $canal),
            'pulso' => $driver->pulso($temporario, $canal, 1000),
            default => $driver->testar($temporario),
        };

        if ($existente) {
            $existente->registrarContato($resultado->sucesso, $resultado->sucesso ? null : $resultado->mensagem);
            activity('dispositivo')->causedBy(auth()->user())->performedOn($existente)
                ->withProperties(['acao' => $acao, 'canal' => $canal, 'sucesso' => $resultado->sucesso, 'condominio_id' => $existente->condominio_id])
                ->log('Teste manual: '.$acao);
        }

        $this->ultimoTeste = [
            'sucesso' => $resultado->sucesso,
            'mensagem' => $resultado->mensagem,
            'ligado' => $resultado->ligado,
            'duracao' => $resultado->duracaoMs,
            'acao' => $acao,
            'canal' => $canal,
            'registro' => $resultado->dados['registro'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(): array
    {
        $classe = app(RegistroDeDrivers::class)->classe($this->driver);
        $regras = [
            'nome' => ['required', 'string', 'max:255'],
            'driver' => ['required', Rule::in(array_keys($this->drivers))],
            'host' => [$classe::usaHost() ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'porta' => ['nullable', 'integer', 'between:1,65535'],
            'canais' => ['required', 'integer', 'between:1,'.$classe::maximoDeCanais()],
            'ativo' => ['boolean'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ];
        $atributos = ['host' => 'endereço (IP)'];

        foreach ($classe::campos() as $campo) {
            $regrasCampo = $campo->regrasDeValidacao();
            if ($campo->tipo === 'senha' && in_array($campo->nome, $this->segredosSalvos, true)) {
                $regrasCampo[0] = 'nullable'; // mantém o valor salvo se ficar em branco
            }
            $regras['config.'.$campo->nome] = $regrasCampo;
            $atributos['config.'.$campo->nome] = mb_strtolower($campo->rotulo);
        }

        $dados = $this->validate($regras, attributes: $atributos);
        unset($dados['config']);
        $dados['host'] = $dados['host'] ?: null;
        $dados['porta'] = $dados['porta'] !== '' && $dados['porta'] !== null ? (int) $dados['porta'] : null;

        return $dados;
    }

    /**
     * @return array<string, mixed>
     */
    private function configFinal(?Dispositivo $existente): array
    {
        $final = [];

        foreach ($this->classe::campos() as $campo) {
            $valor = $this->config[$campo->nome] ?? null;

            if ($campo->tipo === 'senha' && blank($valor) && $existente && $existente->driver === $this->driver) {
                $valor = $existente->config[$campo->nome] ?? null;
            }

            $final[$campo->nome] = match ($campo->tipo) {
                'booleano' => (bool) $valor,
                'numero' => $valor === null || $valor === '' ? null : $valor + 0,
                default => $valor === null ? null : trim((string) $valor),
            };
        }

        return $final;
    }

    private function aplicarPadroes(): void
    {
        foreach ($this->campos as $campo) {
            $this->config[$campo->nome] = $campo->padrao ?? ($campo->tipo === 'booleano' ? false : '');
        }
        $this->porta = (string) ($this->classe::portaPadrao() ?? '');
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-cabecalho-pagina :titulo="$dispositivoId ? __('Editar dispositivo') : __('Novo dispositivo')" :subtitulo="$dispositivoId ? $nome : __('Cadastre um relé ou acionador.')">
        <x-slot:acoes>
            <flux:button variant="ghost" icon="arrow-left" :href="route('dispositivos.index')" wire:navigate>{{ __('Voltar') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    {{-- autocomplete off: impede o navegador de colocar o e-mail/senha do usuário nos campos do equipamento --}}
    <form wire:submit="salvar" class="space-y-8" autocomplete="off">
        <flux:fieldset>
            <flux:legend>{{ __('Equipamento') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><flux:input wire:model="nome" :label="__('Nome')" :placeholder="__('Ex.: Relé portaria, Shelly garagem')" required /></div>
                <div class="sm:col-span-2">
                    <flux:select wire:model.live="driver" :label="__('Modelo / driver')" :description:trailing="$this->classe::descricao()">
                        @foreach ($this->drivers as $chave => $classe)
                            <flux:select.option :value="$chave">{{ $classe::nome() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                @if ($this->classe::usaHost())
                    <flux:input wire:model="host" :label="__('Endereço (IP)')" placeholder="192.168.0.50" class="font-mono" autocomplete="off" required />
                    <flux:input wire:model="porta" type="number" :label="__('Porta')" :placeholder="(string) $this->classe::portaPadrao()" />
                @endif
                <flux:input wire:model="canais" type="number" min="1" :max="$this->classe::maximoDeCanais()" :label="__('Quantidade de canais (relés)')" />
                <div class="flex items-end pb-2"><flux:switch wire:model="ativo" :label="__('Dispositivo ativo')" /></div>
            </div>
        </flux:fieldset>

        @if ($this->campos)
            <flux:fieldset wire:key="campos-{{ $driver }}">
                <flux:legend>{{ __('Configuração do :modelo', ['modelo' => $this->classe::nome()]) }}</flux:legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($this->campos as $campo)
                        @php($salvo = in_array($campo->nome, $segredosSalvos, true))
                        <div @class(['sm:col-span-2' => in_array($campo->tipo, ['texto', 'senha']) && str_starts_with($campo->nome, 'url') || $campo->tipo === 'booleano'])>
                            @switch($campo->tipo)
                                @case('selecao')
                                    <flux:select wire:model="config.{{ $campo->nome }}" :label="$campo->rotulo" :description:trailing="$campo->ajuda">
                                        @foreach ($campo->opcoes as $valor => $rotulo)
                                            <flux:select.option :value="$valor">{{ $rotulo }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @break
                                @case('booleano')
                                    <flux:switch wire:model="config.{{ $campo->nome }}" :label="$campo->rotulo" :description="$campo->ajuda" />
                                    @break
                                @case('senha')
                                    <flux:input type="password" viewable wire:model="config.{{ $campo->nome }}" :label="$campo->rotulo"
                                        :placeholder="$salvo ? __('•••••••• (salva — deixe em branco para manter)') : $campo->placeholder"
                                        :description:trailing="$campo->ajuda" autocomplete="new-password" data-1p-ignore data-lpignore="true" />
                                    @break
                                @default
                                    <flux:input wire:model="config.{{ $campo->nome }}" :type="$campo->tipo === 'numero' ? 'number' : 'text'"
                                        :label="$campo->rotulo" :placeholder="$campo->placeholder" :description:trailing="$campo->ajuda" autocomplete="off" data-1p-ignore data-lpignore="true" />
                            @endswitch
                        </div>
                    @endforeach
                </div>
            </flux:fieldset>
        @endif

        <flux:fieldset>
            <flux:legend>{{ __('Testar') }}</flux:legend>
            <flux:description class="mb-4">{{ __('Usa os dados desta tela, mesmo antes de salvar. "Ligar" deixa o relé ligado até você clicar em "Desligar".') }}</flux:description>
            <div class="flex flex-wrap items-end gap-2">
                <div class="w-28"><flux:input wire:model="canalTeste" type="number" min="1" :label="__('Canal')" /></div>
                <flux:button icon="signal" wire:click="testar('testar')" wire:target="testar" wire:loading.attr="disabled">{{ __('Testar comunicação') }}</flux:button>
                <flux:button icon="bolt" wire:click="testar('pulso')" wire:target="testar" wire:loading.attr="disabled">{{ __('Pulso 1 s') }}</flux:button>
                <flux:button wire:click="testar('ligar')" wire:target="testar" wire:loading.attr="disabled">{{ __('Ligar') }}</flux:button>
                <flux:button wire:click="testar('desligar')" wire:target="testar" wire:loading.attr="disabled">{{ __('Desligar') }}</flux:button>
                <flux:icon.loading wire:loading wire:target="testar" class="mb-2 size-5 text-zinc-400" />
            </div>

            @if ($ultimoTeste)
                <flux:callout class="mt-4" :variant="$ultimoTeste['sucesso'] ? 'success' : 'danger'" :icon="$ultimoTeste['sucesso'] ? 'check-circle' : 'x-circle'"
                    :heading="($ultimoTeste['sucesso'] ? __('Funcionou') : __('Falhou')).' — '.$ultimoTeste['duracao'].' ms'">
                    <flux:callout.text>
                        {{ $ultimoTeste['mensagem'] }}
                        @if ($ultimoTeste['ligado'] !== null)
                            <br>{{ __('Canal :c está :e.', ['c' => $ultimoTeste['canal'], 'e' => $ultimoTeste['ligado'] ? __('LIGADO') : __('desligado')]) }}
                        @endif
                    </flux:callout.text>
                </flux:callout>

                @if (! empty($ultimoTeste['registro']))
                    <details class="mt-2">
                        <summary class="cursor-pointer text-sm text-zinc-500">{{ __('Detalhes técnicos (conversa com o aparelho)') }}</summary>
                        <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-zinc-100 p-3 text-xs whitespace-pre-wrap break-all dark:bg-zinc-900">{{ implode("\n", $ultimoTeste['registro']) }}</pre>
                    </details>
                @endif
            @endif
        </flux:fieldset>

        @if ($this->ehLeitoraSalva)
            <flux:fieldset>
                <flux:legend>{{ __('Pessoas na leitora facial') }}</flux:legend>
                <flux:description class="mb-4">{{ __('As pessoas são enviadas sozinhas ao salvar o cadastro delas. Use os botões para a primeira carga, depois de trocar a leitora ou se ela ficou desligada.') }}</flux:description>

                <div class="mb-4 grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="text-2xl font-semibold">{{ $this->resumoDaLeitora['liberadas'] }}</div>
                        <div class="text-xs text-zinc-500">{{ __('com foto e autorização') }}</div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="text-2xl font-semibold text-green-600">{{ $this->resumoDaLeitora['na_leitora'] }}</div>
                        <div class="text-xs text-zinc-500">{{ __('na leitora') }}</div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="text-2xl font-semibold {{ $this->resumoDaLeitora['com_erro'] ? 'text-amber-500' : '' }}">{{ $this->resumoDaLeitora['com_erro'] }}</div>
                        <div class="text-xs text-zinc-500">{{ __('com erro') }}</div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button icon="arrow-path" wire:click="sincronizarPessoas(false)" wire:target="sincronizarPessoas" wire:loading.attr="disabled">{{ __('Sincronizar agora') }}</flux:button>
                    <flux:button variant="ghost" wire:click="sincronizarPessoas(true)" wire:target="sincronizarPessoas" wire:loading.attr="disabled"
                        wire:confirm="{{ __('Reenviar TODAS as pessoas para a leitora? Pode levar alguns minutos.') }}">{{ __('Reenviar todas') }}</flux:button>
                    <span wire:loading wire:target="sincronizarPessoas" class="text-sm text-zinc-500">{{ __('Sincronizando… não feche a página.') }}</span>
                </div>

                @if ($ultimaSincronizacao)
                    <flux:callout class="mt-4" :variant="$ultimaSincronizacao['erros'] ? 'warning' : 'success'" :icon="$ultimaSincronizacao['erros'] ? 'exclamation-triangle' : 'check-circle'"
                        :heading="__(':e enviada(s), :r removida(s), :s sem mudança — :t s', ['e' => $ultimaSincronizacao['enviadas'], 'r' => $ultimaSincronizacao['removidas'], 's' => $ultimaSincronizacao['sem_mudanca'], 't' => $ultimaSincronizacao['segundos']])">
                        @if ($ultimaSincronizacao['erros'])
                            <flux:callout.text>
                                <ul class="list-disc pl-4">
                                    @foreach (array_slice($ultimaSincronizacao['erros'], 0, 30) as $erro)
                                        <li>{{ $erro }}</li>
                                    @endforeach
                                </ul>
                            </flux:callout.text>
                        @endif
                    </flux:callout>
                @endif
            </flux:fieldset>
        @endif

        <flux:textarea wire:model="observacoes" :label="__('Observações')" rows="2" :placeholder="__('Local de instalação, fiação, etc.')" />

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="route('dispositivos.index')" wire:navigate>{{ __('Cancelar') }}</flux:button>
            <flux:button type="submit" variant="primary" icon="check">{{ __('Salvar') }}</flux:button>
        </div>
    </form>
</section>
