<?php

use App\Enums\StatusMonitoramento;
use App\Models\Condominio;
use App\Models\Configuracao;
use App\Models\Dispositivo;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Mapa geral')] class extends Component {
    public function mount(): void
    {
        $this->autorizar();
    }

    /**
     * Central + condomínios (ligados por VPN), com o status somado de cada um.
     *
     * @return array{nos: list<array<string, mixed>>, ligacoes: list<array<string, mixed>>}
     */
    #[Renderless]
    public function dados(): array
    {
        $this->autorizar();
        $condominios = auth()->user()->condominiosAcessiveis()->get();
        $dispositivos = Dispositivo::query()->whereIn('condominio_id', $condominios->pluck('id'))->get()->groupBy('condominio_id');

        $central = [
            'id' => 'central',
            'nome' => __('Central'),
            'icone' => 'cloud',
            'subtitulo' => parse_url(config('app.url'), PHP_URL_HOST),
            'status' => 'online',
            'rodape' => __('servidor central'),
            'x' => (int) Configuracao::obter('mapa_geral.central_x', 520),
            'y' => (int) Configuracao::obter('mapa_geral.central_y', 40),
        ];

        $nos = [$central];
        $ligacoes = [];

        foreach ($condominios->values() as $i => $c) {
            $lista = $dispositivos->get($c->id, collect());
            $monitorados = $lista->filter(fn (Dispositivo $d) => ! in_array($d->statusEfetivo(), [StatusMonitoramento::NaoMonitorado, StatusMonitoramento::Desconhecido], true));
            $offline = $monitorados->filter(fn (Dispositivo $d) => $d->status === StatusMonitoramento::Offline)->count();
            $lentos = $monitorados->filter(fn (Dispositivo $d) => $d->status === StatusMonitoramento::Lento)->count();
            $total = $monitorados->count();

            $status = match (true) {
                $total === 0 => 'nao_monitorado',
                $offline === $total => 'offline',
                $offline > 0 || $lentos > 0 => 'parcial',
                default => 'online',
            };

            $nos[] = [
                'id' => $c->id,
                'uuid' => $c->uuid,
                'nome' => $c->nome,
                'icone' => 'building-office-2',
                'subtitulo' => collect([$c->cidade, $c->uf])->filter()->join(' / '),
                'status' => $status,
                'rodape' => $total === 0
                    ? __('sem monitoramento')
                    : __(':ok/:total online', ['ok' => $total - $offline, 'total' => $total]).($lentos ? ' · '.__(':n lento(s)', ['n' => $lentos]) : ''),
                'offline' => $monitorados->filter(fn (Dispositivo $d) => $d->status === StatusMonitoramento::Offline)->pluck('nome')->values(),
                'x' => $c->mapa_x ?? 60 + ($i % 5) * 230,
                'y' => $c->mapa_y ?? 280 + intdiv($i, 5) * 170,
            ];

            $ligacoes[] = ['id' => 'vpn-'.$c->id, 'origem' => 'central', 'destino' => $c->id, 'tipo' => 'vpn', 'descricao' => null];
        }

        return ['nos' => $nos, 'ligacoes' => $ligacoes, 'monitor' => \App\Monitoramento\Monitor::estadoDoAgendador()];
    }

    #[Renderless]
    public function mover(int|string $id, int $x, int $y): void
    {
        $this->autorizar();
        $x = max(0, min(5000, $x));
        $y = max(0, min(5000, $y));

        if ($id === 'central') {
            abort_unless(auth()->user()->isSuperAdmin(), 403);
            Configuracao::definir('mapa_geral.central_x', (string) $x);
            Configuracao::definir('mapa_geral.central_y', (string) $y);

            return;
        }

        abort_unless(auth()->user()->isSuperAdmin(), 403);
        Condominio::whereKey((int) $id)->update(['mapa_x' => $x, 'mapa_y' => $y]);
    }

    /**
     * @return array<string, mixed>
     */
    #[Renderless]
    public function detalhes(int|string $id): array
    {
        $this->autorizar();

        if ($id === 'central') {
            return [];
        }

        $c = auth()->user()->condominiosAcessiveis()->whereKey((int) $id)->firstOrFail();

        return [
            'quedas_abertas' => $c->quedas()->whereNull('fim')->with('dispositivo:id,nome')->get()
                ->map(fn ($q) => ['nome' => $q->dispositivo?->nome, 'duracao' => $q->duracaoTexto()])->all(),
            'quedas_24h' => $c->quedas()->where('inicio', '>=', now()->subDay())->count(),
            'selecionar' => route('condominio.selecionar', $c),
        ];
    }

    private function autorizar(): void
    {
        // Quem atende vários condomínios: super admin e operadores da central.
        $usuario = auth()->user();
        abort_unless($usuario?->ehEquipeDaCentral(), 403);
    }
}; ?>

<section class="w-full" x-data="mapaDeRede({ podeEditar: @js(auth()->user()->isSuperAdmin()), permiteLigar: false, intervalo: 30000 })" x-on:tempo-real-rede.window="carregar()">
    <x-cabecalho-pagina :titulo="__('Mapa geral')" :subtitulo="__('Todos os condomínios ligados à central. Clique em um condomínio para ver os detalhes.')" />

    <div class="mb-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-zinc-600 dark:text-zinc-400">
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-green-500"></span>{{ __('Tudo online') }}</span>
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-amber-500"></span>{{ __('Algum equipamento fora ou lento') }}</span>
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-red-500"></span>{{ __('Tudo fora (sem comunicação)') }}</span>
        <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-zinc-300"></span>{{ __('Sem monitoramento') }}</span>
    </div>

    <div class="relative">
        @include('pages.rede.partials.tela')

        <div x-show="selecionado && selecionado.id !== 'central'" x-cloak x-transition.opacity
            class="absolute top-3 right-3 z-30 w-80 max-w-[calc(100%-1.5rem)] rounded-xl border border-zinc-200 bg-white p-4 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
            <template x-if="selecionado">
                <div class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-semibold text-zinc-800 dark:text-white" x-text="selecionado.nome"></div>
                            <div class="text-xs text-zinc-500" x-text="selecionado.rodape"></div>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="x-mark" x-on:click="fecharPainel()" />
                    </div>
                    <template x-if="detalhes">
                        <div class="space-y-2">
                            <div class="text-xs font-medium text-zinc-500">{{ __('Fora do ar agora') }}</div>
                            <template x-if="detalhes.quedas_abertas.length === 0"><div class="text-xs text-green-700 dark:text-green-400">{{ __('Nenhum equipamento fora.') }}</div></template>
                            <template x-for="(q, i) in detalhes.quedas_abertas" :key="i">
                                <div class="flex justify-between text-xs"><span x-text="q.nome"></span><span class="font-semibold text-red-600" x-text="q.duracao"></span></div>
                            </template>
                            <div class="text-xs text-zinc-500" x-text="@js(__('Quedas nas últimas 24 h:')) + ' ' + detalhes.quedas_24h"></div>
                            <form method="POST" :action="detalhes.selecionar">
                                @csrf
                                <input type="hidden" name="voltar" value="mapa">
                                <flux:button type="submit" size="sm" variant="primary" icon="arrow-right">{{ __('Abrir mapa do condomínio') }}</flux:button>
                            </form>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</section>
