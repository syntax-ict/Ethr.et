// ETHR Service Worker — offline-first for Ethiopian field workers
// Version is baked in at build time via build process / cache-busting
// v2 (2026-10-12): bumping it makes activate delete every v1 cache, which is
// how responses stored before the prefixes below were excluded leave devices.
const CACHE_VERSION = 'ethr-v2';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const API_CACHE = `${CACHE_VERSION}-api`;

// API responses that are never stored. The platform console reads every
// tenant, and /auth answers describe the signed-in account; neither may sit in
// Cache Storage after the session ends (console QA F3). They still go to the
// network as usual — only the offline copy is refused.
const NEVER_CACHED_API = ['/api/v1/admin/', '/api/v1/auth/'];

// Shell assets to precache — these make the app load offline
const SHELL_ASSETS = [
  '/',
  '/offline',
  '/attendance/mobile',
  '/kiosk',
];

// ── Install: cache app shell ──────────────────────────────────────────────────
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => {
      // Non-fatal: if individual assets fail, still install
      return Promise.allSettled(
        SHELL_ASSETS.map(url => cache.add(url).catch(() => {}))
      );
    }).then(() => self.skipWaiting())
  );
});

// ── Activate: clean old caches ────────────────────────────────────────────────
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k.startsWith('ethr-') && k !== STATIC_CACHE && k !== API_CACHE)
          .map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── Fetch: network-first for API, cache-first for static ─────────────────────
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET and non-http(s) requests
  if (request.method !== 'GET' || !url.protocol.startsWith('http')) return;

  // Never intercept in local development — let the dev server handle everything.
  // Otherwise a slow dev server or unreachable backend gets misread as "offline".
  if (
    url.hostname === 'localhost' ||
    url.hostname === '127.0.0.1' ||
    url.hostname === '0.0.0.0'
  ) {
    return;
  }

  if (NEVER_CACHED_API.some(prefix => url.pathname.startsWith(prefix))) return;

  // API calls: network-first, fall back to cached response
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(networkFirstWithCache(request, API_CACHE));
    return;
  }

  // Next.js _next/static: cache-first (immutable)
  if (url.pathname.startsWith('/_next/static/')) {
    event.respondWith(cacheFirst(request, STATIC_CACHE));
    return;
  }

  // App shell pages: network-first, fall back to /offline
  if (!url.pathname.includes('.')) {
    event.respondWith(
      fetch(request).catch(() =>
        caches.match(request).then(cached => cached ?? caches.match('/offline'))
      )
    );
    return;
  }
});

// ── Background Sync: flush offline attendance queue ───────────────────────────
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-attendance') {
    event.waitUntil(syncAttendance());
  }
});

async function syncAttendance() {
  // Broadcast to all clients — the useOfflineSync hook in the app
  // will handle the actual API calls, keeping auth tokens in the client
  const clients = await self.clients.matchAll({ type: 'window' });
  clients.forEach(client => client.postMessage({ type: 'SYNC_ATTENDANCE' }));
}

// ── Push Notifications ────────────────────────────────────────────────────────
self.addEventListener('push', (event) => {
  if (!event.data) return;

  let payload;
  try {
    payload = event.data.json();
  } catch {
    payload = { title: 'ETHR', body: event.data.text() };
  }

  event.waitUntil(
    self.registration.showNotification(payload.title ?? 'ETHR', {
      body: payload.body ?? '',
      icon: '/icons/icon-192.png',
      badge: '/icons/badge-72.png',
      tag: payload.tag ?? 'ethr-notification',
      data: payload.data ?? {},
      requireInteraction: payload.requireInteraction ?? false,
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url ?? '/dashboard';
  event.waitUntil(
    self.clients.matchAll({ type: 'window' }).then(clients => {
      const existing = clients.find(c => c.url.includes(url));
      if (existing) return existing.focus();
      return self.clients.openWindow(url);
    })
  );
});

// ── Helpers ───────────────────────────────────────────────────────────────────
async function networkFirstWithCache(request, cacheName) {
  const cache = await caches.open(cacheName);
  try {
    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());
    return response;
  } catch {
    const cached = await cache.match(request);
    return cached ?? new Response(
      JSON.stringify({ error: 'offline', message: 'No network connection' }),
      { status: 503, headers: { 'Content-Type': 'application/json' } }
    );
  }
}

async function cacheFirst(request, cacheName) {
  const cached = await caches.match(request);
  if (cached) return cached;
  const cache = await caches.open(cacheName);
  const response = await fetch(request);
  if (response.ok) cache.put(request, response.clone());
  return response;
}
