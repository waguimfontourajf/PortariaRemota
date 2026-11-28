/**
 * Player de câmera "por quadros": busca uma foto nova logo depois que a
 * anterior chega (~1 a 2 por segundo), só enquanto a câmera está visível
 * na tela. Mostra a mensagem de erro do servidor se a câmera falhar.
 *
 *   <div x-data="cameraAoVivo('/cameras/UUID/imagem')"> ... <img x-ref="imagem"> ... </div>
 */
export default function cameraAoVivo(url, intervalo = 700) {
    return {
        url,
        erro: null,
        carregando: true,
        visivel: false,
        ativo: true,
        urlAtual: null,
        observador: null,

        init() {
            this.observador = new IntersectionObserver((entradas) => {
                this.visivel = entradas.some((e) => e.isIntersecting);
            });
            this.observador.observe(this.$el);
            this.ciclo();
        },

        destroy() {
            this.ativo = false;
            this.observador?.disconnect();
            if (this.urlAtual) URL.revokeObjectURL(this.urlAtual);
        },

        async ciclo() {
            while (this.ativo && this.$el.isConnected) {
                if (this.visivel && !document.hidden) {
                    await this.buscar();
                    await new Promise((r) => setTimeout(r, this.erro ? 5000 : intervalo));
                } else {
                    await new Promise((r) => setTimeout(r, 500));
                }
            }
        },

        async buscar() {
            try {
                const resposta = await fetch(this.url + '?t=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' });
                if (!resposta.ok) {
                    this.erro = (await resposta.text()).slice(0, 200) || 'Câmera indisponível.';
                    return;
                }
                const blob = await resposta.blob();
                const nova = URL.createObjectURL(blob);
                const anterior = this.urlAtual;
                this.urlAtual = nova;
                this.$refs.imagem.src = nova;
                if (anterior) setTimeout(() => URL.revokeObjectURL(anterior), 1000);
                this.erro = null;
            } catch (e) {
                this.erro = 'Sem conexão com o servidor.';
            } finally {
                this.carregando = false;
            }
        },

        telaCheia() {
            const el = this.$el;
            (el.requestFullscreen || el.webkitRequestFullscreen)?.call(el);
        },
    };
}
