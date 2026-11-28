import mapaDeRede from './mapa-de-rede';
import { iniciarTempoReal } from './tempo-real';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mapaDeRede', mapaDeRede);
    window.Alpine.store('tempoReal', { conectado: true });
});

document.addEventListener('livewire:navigated', iniciarTempoReal);
document.addEventListener('DOMContentLoaded', iniciarTempoReal);
