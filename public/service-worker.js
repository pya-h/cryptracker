/**
 * CrypTracker service worker.
 *
 * Caching is deliberately conservative because this app shows per-user,
 * security-sensitive data:
 *
 *   • Navigations (HTML pages) — network-first, falling back to a generic
 *     offline page. The authenticated HTML itself is NEVER cached, so no user's
 *     dashboard/tokens can leak to another via the cache.
 *   • Backend endpoints (*.php: prices, search, exports) — always network,
 *     never cached (stale prices / cached JSON would be wrong or leaky).
 *   • Static assets (/assets/**) — stale-while-revalidate (they are already
 *     versioned with ?v=<mtime>, so updates are picked up automatically).
 *   • Google Fonts — cache-first, so the shell renders offline.
 *
 * Bump VERSION to invalidate the precache after changing this file.
 */

const VERSION = 'v1';
const PRECACHE = 'cryptracker-precache-' + VERSION;
const RUNTIME = 'cryptracker-runtime-' + VERSION;
const FONTS = 'cryptracker-fonts-' + VERSION;

/* Stable, non-versioned files safe to precache (relative to the SW scope). */
const PRECACHE_URLS = [
    'offline.html',
    'manifest.webmanifest',
    'assets/pwa/icon-192.png',
    'assets/pwa/icon-512.png',
    'assets/pwa/icon-maskable-512.png',
];

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(PRECACHE)
            .then(cache => Promise.all(PRECACHE_URLS.map(u => cache.add(u).catch(() => {}))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', event => {
    const keep = new Set([PRECACHE, RUNTIME, FONTS]);
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.map(k => keep.has(k) ? null : caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    const network = fetch(request).then(response => {
        if (response && response.status === 200 && response.type === 'basic') {
            cache.put(request, response.clone());
        }
        return response;
    }).catch(() => cached);
    return cached || network;
}

async function cacheFirst(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    if (cached) return cached;
    try {
        const response = await fetch(request);
        if (response && (response.status === 200 || response.type === 'opaque')) {
            cache.put(request, response.clone());
        }
        return response;
    } catch (e) {
        return cached || Response.error();
    }
}

self.addEventListener('fetch', event => {
    const request = event.request;

    // Only GET is cacheable; let the network handle POST and everything else.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    const sameOrigin = url.origin === self.location.origin;

    // 1. Page navigations: network-first, offline page on failure. Never cached.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('offline.html', { ignoreSearch: true }))
        );
        return;
    }

    // 2. Dynamic backend endpoints: always live, never cached.
    if (sameOrigin && url.pathname.endsWith('.php')) {
        event.respondWith(fetch(request));
        return;
    }

    // 3. Static app assets: stale-while-revalidate.
    if (sameOrigin && url.pathname.includes('/assets/')) {
        event.respondWith(staleWhileRevalidate(request, RUNTIME));
        return;
    }

    // 4. Google Fonts (stylesheet + font files): cache-first for offline shell.
    if (url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
        event.respondWith(cacheFirst(request, FONTS));
        return;
    }

    // 5. Other same-origin static files (manifest, icons): cache-first.
    if (sameOrigin) {
        event.respondWith(cacheFirst(request, PRECACHE));
    }
});
