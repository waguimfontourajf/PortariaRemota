/**
 * Mapa de rede (estilo The Dude): ícones arrastáveis, linhas de ligação e
 * status atualizado periodicamente. Usado pelo mapa do condomínio e pelo
 * mapa geral. Os dados vêm do componente Livewire da página ($wire).
 *
 * Métodos esperados no $wire:
 *   dados()                          -> { nos: [...], ligacoes: [...] }
 *   mover(id, x, y)
 *   detalhes(id)                     -> objeto livre exibido no painel
 *   ligar(origem, destino, tipo, descricao)   (somente mapa do condomínio)
 *   salvarLigacao(id, tipo, descricao) / removerLigacao(id)
 */
export default function mapaDeRede(config = {}) {
    return {
        nos: [],
        ligacoes: [],
        carregado: false,
        podeEditar: !! config.podeEditar,
        permiteLigar: !! config.permiteLigar,
        largura: 2400,
        altura: 1500,
        no_w: 136,
        no_h: 96,

        selecionado: null,
        detalhes: null,
        carregandoDetalhes: false,

        modoLigar: false,
        origemLigacao: null,
        ligacaoEmEdicao: null, // { id?, origem, destino, tipo, descricao }

        arraste: null,
        timer: null,

        async init() {
            await this.carregar();
            this.timer = setInterval(() => this.carregar(), config.intervalo ?? 15000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        async carregar() {
            if (this.arraste) return;
            try {
                const d = await this.$wire.dados();
                this.nos = d.nos;
                this.ligacoes = d.ligacoes;
                this.carregado = true;
                if (this.selecionado) {
                    const atual = this.no(this.selecionado.id);
                    if (atual) this.selecionado = atual;
                }
            } catch (e) {
                // servidor indisponível por instantes: tenta no próximo ciclo
            }
        },

        no(id) {
            return this.nos.find((n) => n.id === id);
        },

        centro(n) {
            return { x: n.x + this.no_w / 2, y: n.y + this.no_h / 2 };
        },

        linhas() {
            return this.ligacoes
                .map((l) => {
                    const a = this.no(l.origem);
                    const b = this.no(l.destino);
                    if (!a || !b) return null;
                    const ca = this.centro(a);
                    const cb = this.centro(b);
                    const fora = a.status === 'offline' || b.status === 'offline';
                    return {
                        ...l,
                        x1: ca.x, y1: ca.y, x2: cb.x, y2: cb.y,
                        mx: (ca.x + cb.x) / 2, my: (ca.y + cb.y) / 2,
                        traco: { cabo: '', fibra: '', wireless: '10 6', vpn: '3 6' }[l.tipo] ?? '',
                        espessura: l.tipo === 'fibra' ? 4 : 2.5,
                        cor: fora ? '#ef4444' : ({ fibra: '#0ea5e9', vpn: '#8b5cf6' }[l.tipo] ?? '#71717a'),
                    };
                })
                .filter(Boolean);
        },

        // ---------------- arrastar ----------------

        pegar(evento, n) {
            if (evento.button !== undefined && evento.button !== 0) return;
            this.arraste = {
                id: n.id,
                inicioX: evento.clientX,
                inicioY: evento.clientY,
                origemX: n.x,
                origemY: n.y,
                moveu: false,
            };
            evento.currentTarget.setPointerCapture?.(evento.pointerId);
        },

        arrastar(evento) {
            const a = this.arraste;
            if (!a) return;
            const dx = evento.clientX - a.inicioX;
            const dy = evento.clientY - a.inicioY;
            if (!a.moveu && Math.abs(dx) + Math.abs(dy) < 5) return;
            if (!this.podeEditar) return;
            a.moveu = true;
            const n = this.no(a.id);
            n.x = Math.max(0, Math.min(this.largura - this.no_w, Math.round((a.origemX + dx) / 10) * 10));
            n.y = Math.max(0, Math.min(this.altura - this.no_h, Math.round((a.origemY + dy) / 10) * 10));
        },

        async soltar() {
            const a = this.arraste;
            this.arraste = null;
            if (!a) return;
            const n = this.no(a.id);
            if (a.moveu) {
                await this.$wire.mover(n.id, n.x, n.y);
            } else {
                this.clicar(n);
            }
        },

        // ---------------- seleção / detalhes ----------------

        async clicar(n) {
            if (this.modoLigar) {
                if (!this.origemLigacao) {
                    this.origemLigacao = n.id;
                } else if (this.origemLigacao !== n.id) {
                    this.ligacaoEmEdicao = { origem: this.origemLigacao, destino: n.id, tipo: 'cabo', descricao: '' };
                    this.origemLigacao = null;
                }
                return;
            }
            if (n.abrir) {
                n.abrir();
                return;
            }
            this.selecionado = n;
            this.detalhes = null;
            this.carregandoDetalhes = true;
            try {
                this.detalhes = await this.$wire.detalhes(n.id);
            } finally {
                this.carregandoDetalhes = false;
            }
        },

        fecharPainel() {
            this.selecionado = null;
            this.detalhes = null;
        },

        // ---------------- ligações ----------------

        alternarModoLigar() {
            this.modoLigar = !this.modoLigar;
            this.origemLigacao = null;
            this.fecharPainel();
        },

        editarLigacao(l) {
            if (!this.podeEditar || !this.permiteLigar) return;
            this.ligacaoEmEdicao = { id: l.id, origem: l.origem, destino: l.destino, tipo: l.tipo, descricao: l.descricao ?? '' };
        },

        async salvarLigacao() {
            const l = this.ligacaoEmEdicao;
            if (l.id) {
                await this.$wire.salvarLigacao(l.id, l.tipo, l.descricao);
            } else {
                await this.$wire.ligar(l.origem, l.destino, l.tipo, l.descricao);
            }
            this.ligacaoEmEdicao = null;
            this.modoLigar = false;
            await this.carregar();
        },

        async removerLigacao() {
            await this.$wire.removerLigacao(this.ligacaoEmEdicao.id);
            this.ligacaoEmEdicao = null;
            await this.carregar();
        },

        nomeDo(id) {
            return this.no(id)?.nome ?? '';
        },

        // ---------------- aparência ----------------

        classesDoNo(n) {
            const base = {
                online: 'border-green-500 bg-green-50 dark:bg-green-950/40',
                lento: 'border-amber-500 bg-amber-50 dark:bg-amber-950/40',
                offline: 'border-red-500 bg-red-50 dark:bg-red-950/40 animate-pulse',
                parcial: 'border-amber-500 bg-amber-50 dark:bg-amber-950/40',
            }[n.status] ?? 'border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800';
            const sel = (this.selecionado?.id === n.id || this.origemLigacao === n.id) ? ' ring-4 ring-blue-400/60' : '';
            return base + sel;
        },
    };
}
