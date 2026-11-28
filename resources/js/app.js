import mapaDeRede from './mapa-de-rede';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mapaDeRede', mapaDeRede);
});
