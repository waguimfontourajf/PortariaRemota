<?php

use App\Cameras\Cameras;
use App\Central\Interfones;
use App\Dispositivos\Campo;
use App\Dispositivos\RegistroDeDrivers;
use App\Enums\MetodoMonitoramento;
use App\Enums\Permissao;
use App\Enums\TipoDispositivo;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Dispositivo;
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
    public string $tipo = 'rele';
    public string $driver = 'shelly';
    public string $mac = '';
    public string $fabricante_modelo = '';
    public bool $monitorar = true;
    public string $metodo_monitoramento = 'ping';
    public string $porta_monitoramento = '';
    public string $host = '';
    public string $porta = '';
    public string $ramal = '';

    /** Link da campainha recém-gerado (mostrado uma vez). */
    public ?string $linkCampainha = null;

    public bool $temCampainha = false;
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
            $this->tipo = $dispositivo->tipo->value;
            $this->driver = (string) $dispositivo->driver;
            $this->mac = (string) $dispositivo->mac;
            $this->fabricante_modelo = (string) $dispositivo->fabricante_modelo;
            $this->monitorar = $dispositivo->monitorar;
            $this->metodo_monitoramento = $dispositivo->metodo_monitoramento->value;
            $this->porta_monitoramento = (string) $dispositivo->porta_monitoramento;
            $this->host = (string) $dispositivo->host;
            $this->porta = (string) $dispositivo->porta;
            $this->ramal = (string) $dispositivo->ramal;
            $this->temCampainha = filled($dispositivo->token_hash);
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
            // Vindo do mapa: já chega com o tipo escolhido.
            $tipo = TipoDispositivo::tryFrom((string) request()->query('tipo', ''));
            if ($tipo) {
                $this->tipo = $tipo->value;
                if (! $tipo->usaDriver()) {
                    $this->driver = '';
                }
            }
            $this->aplicarPadroes();
        }
    }

    /**
     * Relés e leitoras usam driver; equipamentos de rede (roteador, switch...) não.
     */
    #[Computed]
    public function usaDriver(): bool
    {
        return (TipoDispositivo::tryFrom($this->tipo) ?? TipoDispositivo::Rele)->usaDriver();
    }

    public function updatedTipo(): void
    {
        abort_unless(TipoDispositivo::tryFrom($this->tipo) !== null, 422);
        unset($this->usaDriver);

        if ($this->usaDriver && $this->driver === '') {
            $this->driver = (string) array_key_first($this->drivers);
            $this->updatedDriver();
        } elseif (! $this->usaDriver) {
            $this->driver = '';
            $this->config = [];
            $this->segredosSalvos = [];
            unset($this->classe, $this->campos);
            $this->aplicarPadroes();
            if ($this->tipo === 'camera' && $this->porta === '') {
                $this->porta = '80';
            }
            if ($this->metodo_monitoramento === 'driver') {
                $this->metodo_monitoramento = 'ping';
            }
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
    public function classe(): ?string
    {
        return $this->driver !== '' && app(RegistroDeDrivers::class)->existe($this->driver)
            ? app(RegistroDeDrivers::class)->classe($this->driver)
            : null;
    }

    /**
     * @return list<Campo>
     */
    #[Computed]
    public function campos(): array
    {
        return $this->camposPara($this->classe);
    }

    /**
     * Campos de configuração: os do driver (relé/leitora) ou os da câmera.
     *
     * @return list<Campo>
     */
    private function camposPara(?string $classe): array
    {
        return match (true) {
            $classe !== null => $classe::campos(),
            $this->tipo === 'camera' => Cameras::campos(),
            $this->tipo === 'interfone' => Interfones::campos(),
            default => [],
        };
    }

    /** Prévia da imagem no teste da câmera (data URI). */
    public ?string $previaCamera = null;

    public function testarCamera(): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        abort_unless($this->tipo === 'camera', 422);
        $dados = $this->validar();
        $existente = $this->dispositivoId ? $this->condominio()->dispositivos()->findOrFail($this->dispositivoId) : null;

        $temporario = $existente ? clone $existente : new Dispositivo;
        $temporario->forceFill([...$dados, 'config' => $this->configFinal($existente)]);
        $inicio = hrtime(true);

        try {
            $jpeg = app(Cameras::class)->buscar($temporario);
            $this->previaCamera = 'data:image/jpeg;base64,'.base64_encode($jpeg);
            $this->ultimoTeste = ['sucesso' => true, 'mensagem' => __('Imagem recebida (:kb KB).', ['kb' => (int) ceil(strlen($jpeg) / 1024)]), 'ligado' => null,
                'duracao' => (int) ((hrtime(true) - $inicio) / 1_000_000), 'acao' => 'camera', 'canal' => 1, 'registro' => []];
        } catch (\App\Dispositivos\ExcecaoDeComunicacao $e) {
            $this->previaCamera = null;
            $this->ultimoTeste = ['sucesso' => false, 'mensagem' => $e->getMessage(), 'ligado' => null,
                'duracao' => (int) ((hrtime(true) - $inicio) / 1_000_000), 'acao' => 'camera', 'canal' => 1, 'registro' => []];
        }
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

    public function gerarLinkCampainha(): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        abort_unless($this->dispositivoId && $this->tipo === 'interfone', 422);
        $interfone = $this->condominio()->dispositivos()->findOrFail($this->dispositivoId);
        abort_unless($interfone->tipo === TipoDispositivo::Interfone, 422);

        $this->linkCampainha = Interfones::gerarLinkCampainha($interfone);
        $this->temCampainha = true;
    }

    public function salvar(): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        $dados = $this->validar();
        $existente = $this->dispositivoId ? $this->condominio()->dispositivos()->findOrFail($this->dispositivoId) : null;
        $dados['config'] = $this->configFinal($existente);
        if ($this->tipo === 'interfone' && blank($dados['config']['senha_sip'] ?? null)) {
            $dados['config']['senha_sip'] = $existente?->opcao('senha_sip') ?: Interfones::senhaNova();
        }

        if ($existente) {
            $existente->update($dados);
            Flux::toast(variant: 'success', text: __('Dispositivo salvo.'));
        } else {
            // Vindo do mapa: posiciona onde o usuário clicou.
            $novo = $this->condominio()->dispositivos()->make($dados);
            $novo->forceFill([
                'mapa_x' => is_numeric(request()->query('x')) ? (int) request()->query('x') : null,
                'mapa_y' => is_numeric(request()->query('y')) ? (int) request()->query('y') : null,
            ])->save();
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
        $dispositivo = $this->condominio()->dispositivos()->findOrFail($this->dispositivoId);
        $liberadas = app(SincronizacaoFacial::class)->totalParaALeitora($dispositivo);
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
        abort_if($this->classe === null, 422);
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
        $classe = $this->usaDriver && app(RegistroDeDrivers::class)->existe($this->driver)
            ? app(RegistroDeDrivers::class)->classe($this->driver)
            : null;
        $precisaHost = $classe ? $classe::usaHost() : ($this->monitorar && $this->metodo_monitoramento !== 'driver');
        $regras = [
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::enum(TipoDispositivo::class)],
            'driver' => $this->usaDriver ? ['required', Rule::in(array_keys($this->drivers))] : ['nullable'],
            'host' => [$precisaHost ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'porta' => ['nullable', 'integer', 'between:1,65535'],
            'canais' => $classe ? ['required', 'integer', 'between:1,'.$classe::maximoDeCanais()] : ['nullable', 'integer'],
            'mac' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],
            'fabricante_modelo' => ['nullable', 'string', 'max:255'],
            'ativo' => ['boolean'],
            'monitorar' => ['boolean'],
            'metodo_monitoramento' => ['required', Rule::enum(MetodoMonitoramento::class), $classe ? 'string' : Rule::notIn(['driver'])],
            'porta_monitoramento' => [$this->metodo_monitoramento === 'tcp' ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'ramal' => $this->tipo === 'interfone'
                ? ['nullable', 'regex:/^[0-9]{2,10}$/', Rule::notIn([(string) config('portaria.central.numero')]), Rule::unique('dispositivos', 'ramal')->ignore($this->dispositivoId)]
                : ['nullable'],
        ];
        $atributos = ['ramal' => 'ramal', 'host' => 'endereço (IP)', 'mac' => 'MAC', 'porta_monitoramento' => 'porta a testar'];

        foreach ($this->camposPara($classe) as $campo) {
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
        $dados['driver'] = $classe ? $dados['driver'] : null;
        $dados['canais'] = $classe ? (int) $dados['canais'] : 1;
        $dados['mac'] = $dados['mac'] ? strtoupper(str_replace('-', ':', $dados['mac'])) : null;
        $dados['fabricante_modelo'] = $dados['fabricante_modelo'] ?: null;
        $dados['porta_monitoramento'] = $dados['porta_monitoramento'] ? (int) $dados['porta_monitoramento'] : null;
        $dados['ramal'] = $this->tipo === 'interfone' && filled($dados['ramal']) ? $dados['ramal'] : null;

        return $dados;
    }

    /**
     * @return array<string, mixed>
     */
    private function configFinal(?Dispositivo $existente): array
    {
        $final = [];

        foreach ($this->campos as $campo) {
            $valor = $this->config[$campo->nome] ?? null;

            if ($campo->tipo === 'senha' && blank($valor) && $existente && (string) $existente->driver === $this->driver) {
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
        $this->porta = (string) ($this->classe ? ($this->classe::portaPadrao() ?? '') : '');
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-cabecalho-pagina :titulo="$dispositivoId ? __('Editar dispositivo') : __('Novo dispositivo')" :subtitulo="$dispositivoId ? $nome : __('Relé, leitora, câmera, roteador, switch...')">
        <x-slot:acoes>
            <flux:button variant="ghost" icon="arrow-left" :href="route('dispositivos.index')" wire:navigate>{{ __('Voltar') }}</flux:button>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    {{-- autocomplete off: impede o navegador de colocar o e-mail/senha do usuário nos campos do equipamento --}}
    <form wire:submit="salvar" class="space-y-8" autocomplete="off">
        <flux:fieldset>
            <flux:legend>{{ __('Equipamento') }}</flux:legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><flux:input wire:model="nome" :label="__('Nome')" :placeholder="__('Ex.: Relé portaria, Roteador principal, Switch 1º andar')" required /></div>
                <flux:select wire:model.live="tipo" :label="__('Tipo de equipamento')">
                    @foreach (TipoDispositivo::cases() as $t)
                        <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="fabricante_modelo" :label="__('Fabricante / modelo (opcional)')" :placeholder="__('Ex.: MikroTik hEX, Intelbras')" />
                @if ($this->usaDriver)
                    <div class="sm:col-span-2">
                        <flux:select wire:model.live="driver" :label="__('Modelo / driver')" :description:trailing="$this->classe ? $this->classe::descricao() : null">
                            @foreach ($this->drivers as $chave => $classe)
                                <flux:select.option :value="$chave">{{ $classe::nome() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif
                @if (! $this->classe || $this->classe::usaHost())
                    <flux:input wire:model="host" :label="__('Endereço (IP)')" placeholder="192.168.0.50" class="font-mono" autocomplete="off" :required="(bool) $this->classe" />
                    <flux:input wire:model="porta" type="number" :label="__('Porta')" :placeholder="(string) ($this->classe ? $this->classe::portaPadrao() : '')" />
                @endif
                @if ($tipo === 'interfone')
                    <flux:input wire:model="ramal" :label="__('Ramal SIP do interfone')" placeholder="2001" class="font-mono" :description:trailing="__('Número do interfone na central telefônica. A central atende pelo :n.', ['n' => config('portaria.central.numero')])" />
                @endif
                @if ($this->classe)
                    <flux:input wire:model="canais" type="number" min="1" :max="$this->classe::maximoDeCanais()" :label="__('Quantidade de canais (relés)')" />
                @else
                    <flux:input wire:model="mac" :label="__('MAC (opcional)')" placeholder="AA:BB:CC:DD:EE:FF" class="font-mono" />
                @endif
                <div class="flex items-end pb-2"><flux:switch wire:model="ativo" :label="__('Dispositivo ativo')" /></div>
            </div>
        </flux:fieldset>

        @if ($tipo === 'camera')
            <flux:fieldset>
                <flux:legend>{{ __('Testar a câmera') }}</flux:legend>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:button icon="camera" wire:click="testarCamera" wire:target="testarCamera" wire:loading.attr="disabled">{{ __('Buscar imagem agora') }}</flux:button>
                    <flux:icon.loading wire:loading wire:target="testarCamera" class="size-5 text-zinc-400" />
                </div>
                @if ($ultimoTeste && ($ultimoTeste['acao'] ?? null) === 'camera')
                    <flux:callout class="mt-4" :variant="$ultimoTeste['sucesso'] ? 'success' : 'danger'" :icon="$ultimoTeste['sucesso'] ? 'check-circle' : 'x-circle'"
                        :heading="($ultimoTeste['sucesso'] ? __('Funcionou') : __('Falhou')).' — '.$ultimoTeste['duracao'].' ms'">
                        <flux:callout.text>{{ $ultimoTeste['mensagem'] }}</flux:callout.text>
                    </flux:callout>
                @endif
                @if ($previaCamera)
                    <img src="{{ $previaCamera }}" alt="" class="mt-4 w-full rounded-lg">
                @endif
            </flux:fieldset>
        @endif

        @if ($tipo === 'interfone' && $dispositivoId)
            <flux:fieldset>
                <flux:legend>{{ __('Campainha (chamada sem áudio)') }}</flux:legend>
                <flux:text class="mb-3 text-sm">{{ __('Um botão (Shelly i3/i4, interfone com evento HTTP...) pode chamar este link: a chamada aparece na central com a câmera e o botão ABRIR.') }}</flux:text>
                @if ($linkCampainha)
                    <flux:input readonly copyable :value="$linkCampainha" class="font-mono" :description:trailing="__('Guarde agora: por segurança, o link não aparece de novo.')" />
                @endif
                <flux:button class="mt-2" icon="link" wire:click="gerarLinkCampainha" :wire:confirm="$temCampainha ? __('Gerar um link novo? O link antigo para de funcionar.') : null">
                    {{ $temCampainha ? __('Gerar link novo') : __('Gerar link da campainha') }}
                </flux:button>
            </flux:fieldset>
        @endif

        @if ($this->campos)
            <flux:fieldset wire:key="campos-{{ $driver ?: $tipo }}">
                <flux:legend>{{ $this->classe ? __('Configuração do :modelo', ['modelo' => $this->classe::nome()]) : __('Configuração da :tipo', ['tipo' => mb_strtolower(TipoDispositivo::from($tipo)->label())]) }}</flux:legend>
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
            <flux:legend>{{ __('Monitoramento') }}</flux:legend>
            <flux:description class="mb-4">{{ __('O servidor testa o equipamento a cada minuto e avisa pelo Telegram se ele cair.') }}</flux:description>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><flux:switch wire:model.live="monitorar" :label="__('Monitorar este equipamento')" /></div>
                @if ($monitorar)
                    <flux:select wire:model.live="metodo_monitoramento" :label="__('Como testar')">
                        @foreach (MetodoMonitoramento::cases() as $m)
                            @if ($m !== MetodoMonitoramento::Driver || $this->classe)
                                <flux:select.option :value="$m->value">{{ $m->label() }}</flux:select.option>
                            @endif
                        @endforeach
                    </flux:select>
                    @if ($metodo_monitoramento === 'tcp')
                        <flux:input wire:model="porta_monitoramento" type="number" :label="__('Porta a testar')" placeholder="80" :description:trailing="__('Use quando o equipamento não responde a ping. Ex.: 80 (web), 554 (câmera), 6668 (Tuya), 8291 (MikroTik).')" />
                    @endif
                @endif
            </div>
        </flux:fieldset>

        @if ($this->classe)
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
        @endif

        @if ($this->ehLeitoraSalva)
            <flux:fieldset>
                <flux:legend>{{ __('Pessoas na leitora facial') }}</flux:legend>
                <flux:description class="mb-4">{{ __('As pessoas são enviadas sozinhas ao salvar o cadastro delas. Use os botões para a primeira carga, depois de mudar "Quem vai para esta leitora", de trocar a leitora ou se ela ficou desligada.') }}</flux:description>

                <div class="mb-4 grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="text-2xl font-semibold">{{ $this->resumoDaLeitora['liberadas'] }}</div>
                        <div class="text-xs text-zinc-500">{{ __('devem estar na leitora') }}</div>
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
