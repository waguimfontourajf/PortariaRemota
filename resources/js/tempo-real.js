/**
 * Tempo real: pergunta ao servidor "tem novidade?" a cada poucos segundos
 * (rota /novidades) e avisa as telas por eventos do navegador:
 *
 *   tempo-real-acesso   (detail = um acesso: ponto, usuário, resultado...)
 *   tempo-real-acessos  (detail = lista dos acessos novos, uma vez por ciclo)
 *   tempo-real-rede     (detail = queda ou volta de um equipamento)
 *
 * As telas reagem com, por exemplo:  x-on:tempo-real-acessos.window="$wire.$refresh()"
 *
 * Pausa quando a aba está em segundo plano e volta a verificar ao reabrir.
 * Um único vigia por aba (sobrevive à navegação com wire:navigate).
 */
const INTERVALO = 3000;

export function iniciarTempoReal() {
    if (window.__tempoReal) return;

    const meta = document.querySelector('meta[name="tempo-real"]');
    if (!meta) return;

    const url = meta.content;
    const estado = { cursor: null, ocupado: false, falhas: 0 };
    window.__tempoReal = estado;

    const store = () => window.Alpine?.store('tempoReal');

    const toast = (opcoes) => window.Flux?.toast?.({ duration: 8000, ...opcoes });

    const avisar = (nome, detalhe) => window.dispatchEvent(new CustomEvent(nome, { detail: detalhe }));

    async function verificar() {
        if (document.hidden || estado.ocupado) return;
        // Sem sessão (saiu do sistema): para.
        if (!document.querySelector('meta[name="tempo-real"]')) return;

        estado.ocupado = true;
        try {
            const consulta = estado.cursor ? '?' + new URLSearchParams(estado.cursor) : '';
            const resposta = await fetch(url + consulta, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!resposta.ok) throw new Error('HTTP ' + resposta.status);

            const dados = await resposta.json();
            estado.cursor = dados.cursor;
            estado.falhas = 0;
            store() && (store().conectado = true);

            for (const evento of dados.eventos) {
                avisar('tempo-real-acesso', evento);
                if (evento.alerta) {
                    toast({
                        heading: `${evento.ponto ?? ''}: ${evento.resultado_nome}`,
                        text: `${evento.usuario} às ${evento.hora}. ${evento.detalhe ?? ''}`,
                        variant: 'warning',
                    });
                }
            }
            if (dados.eventos.length) avisar('tempo-real-acessos', dados.eventos);

            for (const item of dados.rede) {
                avisar('tempo-real-rede', item);
                toast(item.tipo === 'caiu'
                    ? { heading: `${item.dispositivo} fora do ar`, text: item.condominio, variant: 'danger', duration: 15000 }
                    : { heading: `${item.dispositivo} voltou`, text: `${item.condominio} — ficou fora por ${item.duracao}`, variant: 'success' });
            }
        } catch (e) {
            estado.falhas++;
            if (estado.falhas >= 3) store() && (store().conectado = false);
        } finally {
            estado.ocupado = false;
        }
    }

    setInterval(verificar, INTERVALO);
    document.addEventListener('visibilitychange', () => !document.hidden && verificar());
    verificar();
}
