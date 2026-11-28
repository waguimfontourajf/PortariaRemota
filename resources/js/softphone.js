/**
 * Softphone da central (áudio do interfone no navegador), com JsSIP.
 *
 * Conecta ao Asterisk pelo WebSocket configurado na tela "Central telefônica".
 * Cada ligação que chega traz o cabeçalho X-Portaria-Chamada com o código da
 * chamada no sistema, e o pop-up usa esse código para atender / desligar.
 *
 *   window.softphone.atender(uuid)   window.softphone.encerrar(uuid)
 *   window.softphone.dtmf(uuid, '#1') window.softphone.temLigacao(uuid)
 *
 * Um único telefone por aba: sobrevive à navegação com wire:navigate.
 */
import JsSIP from 'jssip';

const sessoes = new Map(); // uuid (ou "sem-codigo-N") → RTCSession
let ua = null;
let config = null;
let contador = 0;
const audio = new Audio();
audio.autoplay = true;

const store = () => window.Alpine?.store('softphone');
const definir = (estado, mensagem = '') => {
    const s = store();
    if (s) { s.estado = estado; s.mensagem = mensagem; s.ligacoes = [...sessoes.keys()]; }
};
const atualizarLista = () => { const s = store(); if (s) s.ligacoes = [...sessoes.keys()]; };

function chaveDa(sessao) {
    const cabecalho = sessao.direction === 'incoming' ? sessao._request?.getHeader('X-Portaria-Chamada') : null;
    return cabecalho && /^[0-9a-f-]{36}$/i.test(cabecalho) ? cabecalho : 'sem-codigo-' + (++contador);
}

function acharSessao(uuid) {
    if (sessoes.has(uuid)) return sessoes.get(uuid);
    // Ligação sem o cabeçalho (PABX antigo): se só houver uma, é ela.
    const semCodigo = [...sessoes.entries()].filter(([k]) => k.startsWith('sem-codigo-'));
    return semCodigo.length === 1 ? semCodigo[0][1] : null;
}

function ligarAudio(sessao) {
    const ligar = () => {
        const pc = sessao.connection;
        if (!pc) return;
        const receptores = pc.getReceivers().filter((r) => r.track && r.track.kind === 'audio');
        if (receptores.length) {
            audio.srcObject = new MediaStream(receptores.map((r) => r.track));
            audio.play().catch(() => {});
        }
    };
    sessao.on('confirmed', ligar);
    sessao.on('peerconnection', (e) => e.peerconnection.addEventListener('track', ligar));
}

export async function iniciarSoftphone() {
    const el = document.querySelector('[data-softphone]');
    if (!el || window.softphone) return;

    window.softphone = {
        atender(uuid) {
            const sessao = acharSessao(uuid);
            if (!sessao || !sessao.isInProgress()) return false;
            sessao.answer({
                mediaConstraints: { audio: true, video: false },
                pcConfig: { iceServers: config?.stun ? [{ urls: config.stun }] : [] },
            });
            return true;
        },
        encerrar(uuid) {
            const sessao = acharSessao(uuid);
            if (sessao && !sessao.isEnded()) sessao.terminate();
        },
        dtmf(uuid, codigo) {
            const sessao = acharSessao(uuid);
            if (sessao && sessao.isEstablished()) sessao.sendDTMF(codigo, { duration: 160, interToneGap: 120 });
        },
        mudo(ligar) {
            for (const s of sessoes.values()) if (s.isEstablished()) (ligar ? s.mute() : s.unmute());
            const st = store(); if (st) st.mudo = ligar;
        },
        temLigacao(uuid) { return !!acharSessao(uuid); },
        ligar(ramal) {
            if (!ua || !config) return;
            const sessao = ua.call(`sip:${ramal}@${new URL(config.uri.replace('sip:', 'sip://')).hostname}`, {
                mediaConstraints: { audio: true, video: false },
                pcConfig: { iceServers: config.stun ? [{ urls: config.stun }] : [] },
            });
            const chave = 'saida-' + ramal;
            sessoes.set(chave, sessao);
            ligarAudio(sessao);
            const fim = () => { sessoes.delete(chave); atualizarLista(); };
            sessao.on('ended', fim); sessao.on('failed', fim);
            atualizarLista();
        },
    };

    try {
        const resposta = await fetch(el.dataset.softphone, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
        config = await resposta.json();
    } catch (e) {
        definir('erro', 'Não foi possível obter o ramal.');
        return;
    }

    if (!config.ativo) { definir('sem-audio', 'Central sem áudio (Asterisk não configurado).'); return; }
    if (!window.isSecureContext || !navigator.mediaDevices) { definir('erro', 'O áudio precisa de HTTPS.'); return; }

    definir('conectando', 'Conectando ao PABX...');

    ua = new JsSIP.UA({
        sockets: [new JsSIP.WebSocketInterface(config.wss)],
        uri: config.uri,
        authorization_user: config.usuario,
        password: config.senha,
        display_name: config.nome,
        register: true,
        session_timers: false,
        user_agent: 'Portaria Remota',
    });

    ua.on('registered', () => definir('pronto', 'Pronto para atender.'));
    ua.on('unregistered', () => definir('conectando', 'Reconectando...'));
    ua.on('registrationFailed', (e) => definir('erro', 'O PABX recusou o ramal (' + (e.cause || 'erro') + ').'));
    ua.on('disconnected', () => definir('erro', 'Sem conexão com o PABX. Tentando de novo...'));

    ua.on('newRTCSession', ({ session, originator }) => {
        if (originator !== 'remote') return;
        const chave = chaveDa(session);
        sessoes.set(chave, session);
        ligarAudio(session);
        const fim = () => { sessoes.delete(chave); atualizarLista(); window.__tempoReal?.agora?.(); };
        session.on('ended', fim);
        session.on('failed', fim);
        atualizarLista();
        // O pop-up aparece na hora, sem esperar a próxima consulta.
        window.__tempoReal?.agora?.();
    });

    ua.start();
}
