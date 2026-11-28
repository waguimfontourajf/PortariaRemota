/**
 * Toque do interfone (gerado pelo navegador, sem arquivo de áudio) e aviso
 * do sistema quando a aba está em segundo plano.
 *
 * Toca enquanto houver chamada "tocando" em Alpine.store('tempoReal').chamadas.
 */
let ctx = null;
let timer = null;

function bip() {
    if (!ctx) return;
    const agora = ctx.currentTime;
    for (const [inicio, freq] of [[0, 880], [0.25, 660], [0.5, 880], [0.75, 660]]) {
        const osc = ctx.createOscillator();
        const ganho = ctx.createGain();
        osc.frequency.value = freq;
        ganho.gain.setValueAtTime(0.0001, agora + inicio);
        ganho.gain.exponentialRampToValueAtTime(0.25, agora + inicio + 0.02);
        ganho.gain.exponentialRampToValueAtTime(0.0001, agora + inicio + 0.22);
        osc.connect(ganho).connect(ctx.destination);
        osc.start(agora + inicio);
        osc.stop(agora + inicio + 0.24);
    }
}

function tocar(sim) {
    if (sim && !timer) {
        bip();
        timer = setInterval(bip, 2500);
    } else if (!sim && timer) {
        clearInterval(timer);
        timer = null;
    }
}

const avisadas = new Set();

function notificar(chamadas) {
    if (!('Notification' in window) || Notification.permission !== 'granted' || !document.hidden) return;
    for (const c of chamadas) {
        if (c.status !== 'tocando' || avisadas.has(c.uuid)) continue;
        avisadas.add(c.uuid);
        const n = new Notification('Interfone: ' + c.titulo, { body: c.condominio, tag: c.uuid, requireInteraction: true, icon: '/icones/icone-192.png' });
        n.onclick = () => { window.focus(); n.close(); };
    }
}

export function iniciarToque() {
    if (window.__toque) return;
    window.__toque = true;

    // O navegador só libera o som depois de um clique na página.
    const liberar = () => {
        try {
            ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
            ctx.resume();
            const s = window.Alpine?.store('softphone');
            if (s) s.somLiberado = ctx.state === 'running';
        } catch (e) {}
    };
    ['click', 'keydown', 'touchstart'].forEach((ev) => document.addEventListener(ev, liberar, { passive: true }));

    window.addEventListener('tempo-real-chamadas', (e) => {
        const chamadas = e.detail || [];
        tocar(chamadas.some((c) => c.status === 'tocando'));
        notificar(chamadas);
    });
}
