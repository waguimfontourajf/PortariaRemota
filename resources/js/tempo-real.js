/**
 * Tempo real: pergunta ao servidor "tem novidade?" a cada poucos segundos
 * (rota /novidades) e avisa as telas por eventos do navegador:
 *
 *   tempo-real-acesso   (detail = um acesso: ponto, usuário, resultado...)
 *   tempo-real-acessos  (detail = lista dos acessos novos, uma vez por ciclo)
 *   tempo-real-rede     (detail = queda ou volta de um equipamento)
 *   tempo-real-chamadas (detail = chamadas de interfone em andamento; só quando mudam)
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
    const estado = { cursor: null, ocupado: false, falhas: 0, atende: false, chamadas: null };
    window.__tempoReal = estado;

    const store = () => window.Alpine?.store('tempoReal');

    const toast = (opcoes) => window.Flux?.toast?.({ duration: 8000, ...opcoes });

    const avisar = (nome, detalhe) => window.dispatchEvent(new CustomEvent(nome, { detail: detalhe }));

    async function verificar() {
        // Quem atende o interfone continua recebendo chamadas com a aba em segundo plano.
        if ((document.hidden && !estado.atende) || estado.ocupado) return;
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
                if (evento.meu_visitante) {
                    toast(evento.resultado === 'sucesso'
                        ? { heading: `${evento.meu_visitante} chegou`, text: `Abriu: ${evento.ponto} às ${evento.hora}`, variant: 'success', duration: 15000 }
                        : { heading: `${evento.meu_visitante} não conseguiu abrir`, text: evento.detalhe ?? '', variant: 'warning' });
                }
                if (evento.alerta) {
                    toast({
                        heading: `${evento.ponto ?? ''}: ${evento.resultado_nome}`,
                        text: `${evento.usuario} às ${evento.hora}. ${evento.detalhe ?? ''}`,
                        variant: 'warning',
                    });
                }
            }
            if (dados.eventos.length) avisar('tempo-real-acessos', dados.eventos);

            estado.atende = Array.isArray(dados.chamadas);
            if (estado.atende) {
                const assinatura = JSON.stringify(dados.chamadas);
                if (assinatura !== estado.chamadas) {
                    estado.chamadas = assinatura;
                    store() && (store().chamadas = dados.chamadas);
                    avisar('tempo-real-chamadas', dados.chamadas);
                }
            }

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

    // Para verificar na hora (ex.: o softphone recebeu uma ligação).
    estado.agora = () => verificar();

    setInterval(verificar, INTERVALO);
    document.addEventListener('visibilitychange', () => !document.hidden && verificar());
    verificar();
}
