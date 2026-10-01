const CACHE_VERSION = 'dromis-pwa-v20260909-canonical-host-05';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const OFFLINE_URL = '/offline.html';

const PRECACHE_URLS = [
    OFFLINE_URL,
    '/manifest.json',
    '/favicon.ico',
    '/images/pwa-icon-192.png',
    '/images/pwa-icon-512.png',
    '/images/drmd-cir-logo.png',
    '/images/dswd-logo.png',
    '/images/drmd-logo-new.png',
    '/images/bagong-pilipinas.png'
];

const isStaticAsset = (request) => {
    const url = new URL(request.url);

    return request.method === 'GET'
        && url.origin === self.location.origin
        && (
            url.pathname.startsWith('/build/')
            || url.pathname.startsWith('/images/')
            || url.pathname === '/favicon.ico'
            || url.pathname === '/favicon.svg'
            || url.pathname === '/manifest.json'
            || ['style', 'script', 'font', 'image'].includes(request.destination)
        );
};

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys
                .filter((key) => key.startsWith('dromis-pwa-') && !key.startsWith(CACHE_VERSION))
                .map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    if (isStaticAsset(request)) {
        event.respondWith(
            caches.match(request)
                .then((cached) => cached || fetch(request).then((response) => {
                    const copy = response.clone();

                    if (response.ok) {
                        caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                }))
        );
    }
});
