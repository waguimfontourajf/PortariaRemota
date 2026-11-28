import mapaDeRede from './mapa-de-rede';
import cameraAoVivo from './camera-ao-vivo';
import { iniciarTempoReal } from './tempo-real';
import { iniciarSoftphone } from './softphone';
import { iniciarToque } from './toque';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mapaDeRede', mapaDeRede);
    window.Alpine.data('cameraAoVivo', cameraAoVivo);
    window.Alpine.store('tempoReal', { conectado: true, chamadas: [] });
    window.Alpine.store('softphone', { estado: 'desligado', mensagem: '', ligacoes: [], mudo: false, somLiberado: false });
});

document.addEventListener('livewire:navigated', iniciarTempoReal);
document.addEventListener('DOMContentLoaded', iniciarTempoReal);

// Central de atendimento: telefone no navegador e toque do interfone.
for (const evento of ['DOMContentLoaded', 'livewire:navigated']) {
    document.addEventListener(evento, () => { iniciarToque(); iniciarSoftphone(); });
}

// App instalável (PWA): só funciona em HTTPS (ou localhost).
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}

// Guarda o convite de instalação do Android/Chrome para o botão "Instalar".
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    window.__convitePwa = e;
    window.dispatchEvent(new CustomEvent('pwa-instalavel'));
});

// QR code (autorização de visitante): <div x-qrcode="link"></div>
import QRCode from 'qrcode';
document.addEventListener('alpine:init', () => {
    window.Alpine.directive('qrcode', (el, { expression }, { evaluateLater, effect }) => {
        const valor = evaluateLater(expression);
        effect(() => valor((texto) => {
            if (!texto) return;
            QRCode.toString(String(texto), { type: 'svg', margin: 1, errorCorrectionLevel: 'M' })
                .then((svg) => { el.innerHTML = svg; el.querySelector('svg')?.setAttribute('class', 'h-auto w-full'); })
                .catch(() => {});
        }));
    });
});
