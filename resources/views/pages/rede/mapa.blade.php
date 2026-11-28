<?php

use App\Enums\Permissao;
use App\Enums\StatusMonitoramento;
use App\Enums\TipoDispositivo;
use App\Enums\TipoLigacao;
use App\Livewire\Concerns\OperaNoCondominioAtual;
use App\Models\Dispositivo;
use App\Models\LigacaoRede;
use App\Monitoramento\Monitor;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Mapa da rede')] class extends Component {
    use OperaNoCondominioAtual;

    public function mount(): void
    {
        $this->exigirVisualizacao();
    }

    public function podeEditar(): bool
    {
        return (bool) auth()->user()?->can(Permissao::DispositivosGerenciar->value);
    }

    /**
     * @return array{nos: list<array<string, mixed>>, ligacoes: list<array<string, mixed>>}
     */
    #[Renderless]
    public function dados(): array
    {
        $this->exigirVisualizacao();
        $fuso = $this->condominio()->fuso_horario;
        $dispositivos = $this->condominio()->dispositivos()->orderBy('id')->get();

        $nos = $dispositivos->values()->map(function (Dispositivo $d, int $i) use ($fuso) {
            $status = $d->statusEfetivo();

            return [
                'id' => $d->id,
                'nome' => $d->nome,
                'tipo' => $d->tipo->value,
                'tipo_nome' => $d->tipo->label(),
                'icone' => $d->tipo->icone(),
                'host' => $d->host,
                'status' => $status->value,
                'status_nome' => $status->label(),
                'latencia' => $d->latencia_ms,
                'ativo' => $d->ativo,
                // Sem posição salva: distribui em grade.
                'x' => $d->mapa_x ?? 40 + ($i % 6) * 190,
                'y' => $d->mapa_y ?? 40 + intdiv($i, 6) * 150,
                'verificado' => $d->verificado_em?->timezone($fuso)->format('d/m H:i:s'),
                'desde' => $d->status_desde?->diffForHumans(),
                'erro' => $status === StatusMonitoramento::Offline ? $d->ultimo_erro : null,
                'editar' => route('dispositivos.editar', $d),
            ];
        })->all();

        $ligacoes = $this->condominio()->ligacoesRede()->get()->map(fn (LigacaoRede $l) => [
            'id' => $l->id,
            'origem' => $l->origem_id,
            'destino' => $l->destino_id,
            'tipo' => $l->tipo->value,
            'descricao' => $l->descricao,
        ])->all();

        return ['nos' => $nos, 'ligacoes' => $ligacoes, 'monitor' => \App\Monitoramento\Monitor::estadoDoAgendador()];
    }

    #[Renderless]
    public function mover(int $id, int $x, int $y): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        $this->condominio()->dispositivos()->whereKey($id)->update([
            'mapa_x' => max(0, min(5000, $x)),
            'mapa_y' => max(0, min(5000, $y)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    #[Renderless]
    public function detalhes(int $id): array
    {
        $this->exigirVisualizacao();
        $d = $this->condominio()->dispositivos()->findOrFail($id);
        $fuso = $this->condominio()->fuso_horario;

        return [
            'disponibilidade' => [
                '24 horas' => $d->disponibilidade(1),
                '7 dias' => $d->disponibilidade(7),
                '30 dias' => $d->disponibilidade(30),
            ],
            'quedas' => $d->quedas()->latest('inicio')->limit(8)->get()->map(fn ($q) => [
                'inicio' => $q->inicio->timezone($fuso)->format('d/m H:i'),
                'duracao' => $q->duracaoTexto(),
                'aberta' => $q->fim === null,
            ])->all(),
            'mac' => $d->mac,
            'modelo' => $d->fabricante_modelo,
            'metodo' => $d->monitorar ? $d->metodo_monitoramento->label() : null,
        ];
    }

    /**
     * Testa o equipamento agora (sem esperar o próximo minuto).
     */
    #[Renderless]
    public function testarAgora(int $id): void
    {
        $this->exigirVisualizacao();
        $d = $this->condominio()->dispositivos()->findOrFail($id);
        app(Monitor::class)->executar(somente: collect([$d]));
    }

    #[Renderless]
    public function ligar(int $origem, int $destino, string $tipo, ?string $descricao = null): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        $ids = $this->condominio()->dispositivos()->whereKey([$origem, $destino])->pluck('id');
        abort_unless($origem !== $destino && $ids->count() === 2, 422);
        $dados = $this->validarLigacao($tipo, $descricao);

        // Uma linha por par, independente da direção.
        $existente = $this->condominio()->ligacoesRede()
            ->where(fn ($q) => $q->where(['origem_id' => $origem, 'destino_id' => $destino])
                ->orWhere(fn ($q) => $q->where(['origem_id' => $destino, 'destino_id' => $origem])))
            ->first();

        $existente
            ? $existente->update($dados)
            : $this->condominio()->ligacoesRede()->create(['origem_id' => $origem, 'destino_id' => $destino, ...$dados]);
    }

    #[Renderless]
    public function salvarLigacao(int $id, string $tipo, ?string $descricao = null): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        $this->condominio()->ligacoesRede()->findOrFail($id)->update($this->validarLigacao($tipo, $descricao));
    }

    #[Renderless]
    public function removerLigacao(int $id): void
    {
        $this->exigirPermissao(Permissao::DispositivosGerenciar);
        $this->condominio()->ligacoesRede()->findOrFail($id)->delete();
    }

    /**
     * @return array{tipo: string, descricao: ?string}
     */
    private function validarLigacao(string $tipo, ?string $descricao): array
    {
        $dados = validator(['tipo' => $tipo, 'descricao' => $descricao], [
            'tipo' => ['required', Rule::enum(TipoLigacao::class)],
            'descricao' => ['nullable', 'string', 'max:100'],
        ])->validate();

        return ['tipo' => $dados['tipo'], 'descricao' => filled($dados['descricao']) ? trim($dados['descricao']) : null];
    }

    /**
     * Ver o mapa: quem configura equipamentos ou acompanha a operação.
     */
    private function exigirVisualizacao(): void
    {
        $this->condominio();
        $usuario = auth()->user();
        abort_unless($usuario?->can(Permissao::DispositivosGerenciar->value) || $usuario?->can(Permissao::HistoricoVer->value), 403);
    }
}; ?>

<section class="w-full" x-data="mapaDeRede({ podeEditar: @js($this->podeEditar()), permiteLigar: true, intervalo: 30000 })" x-on:tempo-real-rede.window="carregar()">
    <x-cabecalho-pagina :titulo="__('Mapa da rede')" :subtitulo="$this->condominio()->nome.' — '.__('atualiza sozinho ao detectar mudança')">
        <x-slot:acoes>
            @if ($this->podeEditar())
                <flux:button icon="link" x-on:click="alternarModoLigar()" x-bind:class="modoLigar && 'ring-2 ring-blue-500'">
                    <span x-text="modoLigar ? @js(__('Cancelar ligação')) : @js(__('Ligar equipamentos'))"></span>
                </flux:button>
                <flux:dropdown position="bottom" align="end">
                    <flux:button variant="primary" icon="plus" icon:trailing="chevron-down">{{ __('Adicionar') }}</flux:button>
                    <flux:menu class="max-h-96 overflow-y-auto">
                        @foreach (TipoDispositivo::cases() as $t)
                            <flux:menu.item :icon="$t->icone()" :href="route('dispositivos.criar', ['tipo' => $t->value])" wire:navigate>{{ $t->label() }}</flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            @endif
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <div x-show="modoLigar" x-cloak class="mb-3">
        <flux:callout icon="link" variant="secondary">
            <flux:callout.text>
                <span x-show="! origemLigacao">{{ __('Clique no PRIMEIRO equipamento.') }}</span>
                <span x-show="origemLigacao">{{ __('Agora clique no equipamento ao qual ele está ligado.') }}</span>
            </flux:callout.text>
        </flux:callout>
    </div>

    {{-- Legenda --}}
    <div class="mb-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-zinc-600 dark:text-zinc-400">
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-green-500"></span>{{ __('Online') }}</span>
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-amber-500"></span>{{ __('Lento') }}</span>
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-red-500"></span>{{ __('Offline') }}</span>
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-zinc-300"></span>{{ __('Não monitorado') }}</span>
        <span class="flex items-center gap-1.5"><svg width="34" height="6"><line x1="0" y1="3" x2="34" y2="3" stroke="#71717a" stroke-width="2.5"/></svg>{{ __('Cabo') }}</span>
        <span class="flex items-center gap-1.5"><svg width="34" height="6"><line x1="0" y1="3" x2="34" y2="3" stroke="#71717a" stroke-width="2.5" stroke-dasharray="10 6"/></svg>{{ __('Wireless') }}</span>
        <span class="flex items-center gap-1.5"><svg width="34" height="6"><line x1="0" y1="3" x2="34" y2="3" stroke="#0ea5e9" stroke-width="4"/></svg>{{ __('Fibra') }}</span>
        <span class="flex items-center gap-1.5"><svg width="34" height="6"><line x1="0" y1="3" x2="34" y2="3" stroke="#8b5cf6" stroke-width="2.5" stroke-dasharray="3 6"/></svg>{{ __('VPN') }}</span>
    </div>

    <div class="relative">
        @include('pages.rede.partials.tela')

        {{-- Painel de detalhes --}}
        <div x-show="selecionado" x-cloak x-transition.opacity
            class="absolute top-3 right-3 z-30 w-80 max-w-[calc(100%-1.5rem)] rounded-xl border border-zinc-200 bg-white p-4 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
            <template x-if="selecionado">
                <div class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-semibold text-zinc-800 dark:text-white" x-text="selecionado.nome"></div>
                            <div class="text-xs text-zinc-500" x-text="selecionado.tipo_nome"></div>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="x-mark" x-on:click="fecharPainel()" />
                    </div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                        <dt class="text-zinc-500">{{ __('Status') }}</dt><dd x-text="selecionado.status_nome + (selecionado.desde ? ' (' + selecionado.desde + ')' : '')"></dd>
                        <dt class="text-zinc-500">{{ __('IP') }}</dt><dd class="font-mono" x-text="selecionado.host ?? '—'"></dd>
                        <dt class="text-zinc-500">{{ __('Resposta') }}</dt><dd x-text="selecionado.latencia !== null ? selecionado.latencia + ' ms' : '—'"></dd>
                        <dt class="text-zinc-500">{{ __('Verificado') }}</dt><dd x-text="selecionado.verificado ?? '—'"></dd>
                        <template x-if="detalhes?.metodo"><dt class="text-zinc-500">{{ __('Teste') }}</dt></template>
                        <template x-if="detalhes?.metodo"><dd x-text="detalhes.metodo"></dd></template>
                        <template x-if="detalhes?.modelo"><dt class="text-zinc-500">{{ __('Modelo') }}</dt></template>
                        <template x-if="detalhes?.modelo"><dd x-text="detalhes.modelo"></dd></template>
                        <template x-if="detalhes?.mac"><dt class="text-zinc-500">MAC</dt></template>
                        <template x-if="detalhes?.mac"><dd class="font-mono" x-text="detalhes.mac"></dd></template>
                    </dl>
                    <template x-if="selecionado.erro">
                        <div class="rounded-md bg-red-50 p-2 text-xs text-red-700 dark:bg-red-950/50 dark:text-red-300" x-text="selecionado.erro"></div>
                    </template>

                    <div x-show="carregandoDetalhes" class="text-xs text-zinc-500">{{ __('Carregando…') }}</div>
                    <template x-if="detalhes">
                        <div class="space-y-3">
                            <div>
                                <div class="mb-1 text-xs font-medium text-zinc-500">{{ __('Disponibilidade') }}</div>
                                <div class="grid grid-cols-3 gap-2 text-center">
                                    <template x-for="(valor, periodo) in detalhes.disponibilidade" :key="periodo">
                                        <div class="rounded-md bg-zinc-50 p-1.5 dark:bg-zinc-800">
                                            <div class="font-semibold" x-text="valor === null ? '—' : valor.toLocaleString('pt-BR') + '%'"></div>
                                            <div class="text-[10px] text-zinc-500" x-text="periodo"></div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <div>
                                <div class="mb-1 text-xs font-medium text-zinc-500">{{ __('Últimas quedas') }}</div>
                                <template x-if="detalhes.quedas.length === 0"><div class="text-xs text-zinc-500">{{ __('Nenhuma queda registrada.') }}</div></template>
                                <template x-for="(q, i) in detalhes.quedas" :key="i">
                                    <div class="flex justify-between text-xs">
                                        <span x-text="q.inicio"></span>
                                        <span :class="q.aberta ? 'font-semibold text-red-600' : 'text-zinc-500'" x-text="q.aberta ? @js(__('fora agora')) + ' · ' + q.duracao : q.duracao"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div class="flex gap-2 pt-1">
                        <flux:button size="sm" icon="arrow-path" x-on:click="$wire.testarAgora(selecionado.id).then(() => carregar())">{{ __('Testar agora') }}</flux:button>
                        @if ($this->podeEditar())
                            <a :href="selecionado.editar" wire:navigate class="inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"><flux:icon.pencil-square variant="micro" />{{ __('Editar') }}</a>
                        @endif
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- Criar / editar ligação --}}
    <div x-show="ligacaoEmEdicao" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" x-on:keydown.escape.window="ligacaoEmEdicao = null">
        <template x-if="ligacaoEmEdicao">
            <form x-on:submit.prevent="salvarLigacao()" class="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl dark:bg-zinc-900">
                <flux:heading size="lg">{{ __('Ligação') }}</flux:heading>
                <flux:text><b x-text="nomeDo(ligacaoEmEdicao.origem)"></b> ↔ <b x-text="nomeDo(ligacaoEmEdicao.destino)"></b></flux:text>
                <flux:select x-model="ligacaoEmEdicao.tipo" :label="__('Tipo de ligação')">
                    @foreach (TipoLigacao::cases() as $t)
                        <flux:select.option :value="$t->value">{{ $t->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input x-model="ligacaoEmEdicao.descricao" :label="__('Descrição (opcional)')" :placeholder="__('Ex.: porta 3, SSID Portaria, 5 GHz')" maxlength="100" />
                <div class="flex justify-between gap-2">
                    <div>
                        <template x-if="ligacaoEmEdicao.id">
                            <flux:button variant="danger" icon="trash" x-on:click="removerLigacao()">{{ __('Remover') }}</flux:button>
                        </template>
                    </div>
                    <div class="flex gap-2">
                        <flux:button variant="ghost" x-on:click="ligacaoEmEdicao = null">{{ __('Cancelar') }}</flux:button>
                        <flux:button type="submit" variant="primary">{{ __('Salvar') }}</flux:button>
                    </div>
                </div>
            </form>
        </template>
    </div>
</section>
