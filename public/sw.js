/*
 * Service worker do app Portaria Remota.
 *
 * De propósito, NÃO guarda páginas nem dados (acessos, pessoas...) no
 * celular: só a página "sem internet" e os arquivos de tela (CSS/JS/fontes
 * da pasta /build, que têm nome único por versão).
 */
const VERSAO = 'portaria-v1';
const OFFLINE = '/offline.html';
const BASICOS = [OFFLINE, '/icones/icone-192.png', '/favicon.svg'];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(VERSAO).then((c) => c.addAll(BASICOS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((nomes) => Promise.all(nomes.filter((n) => n !== VERSAO).map((n) => caches.delete(n))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Páginas: sempre da internet; sem conexão, mostra a página offline.
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
        return;
    }

    // Arquivos de tela versionados: do cache, se já tiver.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icones/')) {
        e.respondWith(
            caches.match(req).then((cache) => cache || fetch(req).then((resp) => {
                if (resp.ok) {
                    const copia = resp.clone();
                    caches.open(VERSAO).then((c) => c.put(req, copia));
                }
                return resp;
            })),
        );
    }
});
