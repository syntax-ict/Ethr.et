// ETHR Service Worker — offline-first for Ethiopian field workers
// Version is baked in at build time via build process / cache-busting
// v2 (2026-10-12): bumping it makes activate delete every v1 cache, which is
// how responses stored before the prefixes below were excluded leave devices.
// v3 (2026-10-28): the shell pages are stored with the scripts and styles they
// load. v2 stored only their HTML, so /offline came out of the cache and then
// failed on a chunk nothing had fetched (production QA 2026-10-28, F-01).
const CACHE_VERSION = 'ethr-v3';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const API_CACHE = `${CACHE_VERSION}-api`;

// sw.js does not change when a release is deployed, so the worker is not
// reinstalled and would keep the shell of the release it was installed with.
// A navigation that reaches the network re-reads the shell when this is older.
const SHELL_REFRESH_MS = 6 * 60 * 60 * 1000;
const SHELL_STAMP = '/__ethr-shell-cached-at';

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
  // Non-fatal: if individual assets fail, still install
  event.waitUntil(cacheShell().catch(() => {}).then(() => self.skipWaiting()));
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
      fetch(request).then((response) => {
        if (request.mode === 'navigate' && response.ok) {
          event.waitUntil(refreshShellIfStale().catch(() => {}));
        }
        return response;
      }).catch(() =>
        caches.match(request).then(cached => cached ?? caches.match('/offline'))
      )
    );
    return;
  }
});

// ── Shell: each page together with the files it loads ───────────────────────
async function cacheShell() {
  const cache = await caches.open(STATIC_CACHE);
  await cache.put(SHELL_STAMP, new Response(String(Date.now())));
  await Promise.allSettled(SHELL_ASSETS.map(url => cacheShellPage(cache, url)));
}

// The page is stored only once every script and stylesheet it names is, so a
// copy that would render and then fail to hydrate never replaces a working one.
async function cacheShellPage(cache, url) {
  const response = await fetch(url, { cache: 'no-store' });
  if (!response.ok) return;

  const html = await response.clone().text();
  const assets = [...new Set(html.match(/\/_next\/static\/[^"'\s\\)]+/g) || [])];
  const stored = await Promise.allSettled(assets.map(async (asset) => {
    if (!(await cache.match(asset))) await cache.add(asset);
  }));

  if (stored.every(result => result.status === 'fulfilled')) {
    await cache.put(url, response);
  }
}

async function refreshShellIfStale() {
  const cache = await caches.open(STATIC_CACHE);
  const stamp = await cache.match(SHELL_STAMP);
  const cachedAt = stamp ? Number(await stamp.text()) : 0;
  if (Date.now() - cachedAt < SHELL_REFRESH_MS) return;
  await cacheShell();
}

// ── Background Sync: flush offline attendance queue ───────────────────────────
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-attendance') {
    event.waitUntil(syncAttendance());
  }
});

async function syncAttendance() {
  // With ETHR open, the page sends the queue (useOfflineSync): one sender,
  // and it shows the result.
  const clients = await self.clients.matchAll({ type: 'window' });
  if (clients.length > 0) {
    clients.forEach(client => client.postMessage({ type: 'SYNC_ATTENDANCE' }));
    return;
  }

  // With ETHR closed, the worker sends it itself. Until 2026-10-12 it only
  // messaged open windows, so a punch queued offline waited until the app
  // was next opened, however long the connection had been back.
  await flushQueue();
}

// ── Offline punch queue, read directly (mirrors src/src/lib/offline-queue.ts) ─
const QUEUE_DB = 'ethr-offline';
const QUEUE_STORE = 'attendance_queue';

function openQueue() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(QUEUE_DB, 1);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(QUEUE_STORE)) {
        const store = db.createObjectStore(QUEUE_STORE, { keyPath: 'id', autoIncrement: true });
        store.createIndex('synced', 'synced', { unique: false });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function pendingPunches(db) {
  return new Promise((resolve, reject) => {
    const req = db.transaction(QUEUE_STORE, 'readonly').objectStore(QUEUE_STORE).index('synced').getAll(0);
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function updatePunch(db, id, changes) {
  return new Promise((resolve, reject) => {
    const store = db.transaction(QUEUE_STORE, 'readwrite').objectStore(QUEUE_STORE);
    const get = store.get(id);
    get.onsuccess = () => {
      if (!get.result) return resolve();
      const put = store.put({ ...get.result, ...changes });
      put.onsuccess = () => resolve();
      put.onerror = () => reject(put.error);
    };
    get.onerror = () => reject(get.error);
  });
}

async function xsrfToken() {
  // Sanctum's CSRF check needs the XSRF-TOKEN cookie echoed in a header; a
  // worker has no document.cookie, only the Cookie Store API (Chromium, which
  // is also the only engine with Background Sync).
  if (!self.cookieStore) return null;
  try {
    const cookie = await self.cookieStore.get('XSRF-TOKEN');
    return cookie ? decodeURIComponent(cookie.value) : null;
  } catch (e) {
    return null;
  }
}

async function flushQueue() {
  const db = await openQueue();
  const pending = await pendingPunches(db);
  if (pending.length === 0) return;

  const token = await xsrfToken();
  if (!token) return; // the app sends them when next opened

  // One request per tenant header, as the app would have sent each.
  const groups = new Map();
  for (const r of pending) {
    const key = r.tenant || '';
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(r);
  }

  for (const [tenant, records] of groups) {
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': token,
    };
    if (tenant) headers['X-Tenant'] = tenant;

    const response = await fetch('/api/v1/attendance/sync', {
      method: 'POST',
      credentials: 'include',
      headers,
      body: JSON.stringify({
        records: records.map((r) => ({
          employee_public_id: r.employee_public_id,
          type: r.type,
          timestamp: r.captured_at,
          idempotency_key: r.idempotency_key,
          offline_token: r.offline_token,
          latitude: r.latitude,
          longitude: r.longitude,
          accuracy: r.accuracy ?? null,
          photo: r.photo ?? null,
          altitude: r.altitude,
          speed: r.speed,
          heading: r.heading,
          fix_age_ms: r.fix_age_ms,
          device_id: r.device_id,
          webdriver: r.webdriver,
          liveness: r.liveness ?? null,
          device_proof: r.device_proof ?? null,
          // When the batch is sent, so the server can see the phone's clock.
          client_time: new Date().toISOString(),
        })),
      }),
    });

    // Signed out, expired token, server down: leave the queue for the app.
    // Throwing tells the browser to retry this sync later.
    if (!response.ok) {
      if (response.status >= 500) throw new Error('sync failed: ' + response.status);
      return;
    }

    const data = await response.json();
    for (const result of data.results || []) {
      const record = records.find((r) => r.idempotency_key === result.idempotency_key);
      if (!record || !record.id) continue;
      if (result.status === 'created' || result.status === 'duplicate') {
        await updatePunch(db, record.id, { synced: 1, sync_error: null });
      } else {
        await updatePunch(db, record.id, { sync_error: result.detail || 'Sync failed' });
      }
    }
  }

  // Only with permission already given: showNotification rejects without it,
  // and a rejection here would make the browser retry a sync that worked.
  if (self.Notification && self.Notification.permission === 'granted') {
    const amharic = pending.some((r) => r.locale === 'am');
    try {
      await self.registration.showNotification('ETHR', {
        body: amharic
          ? 'ከመስመር ውጭ የተመዘገበው ክትትልዎ ተልኳል።'
          : 'Your offline attendance was sent.',
        tag: 'ethr-offline-sync',
        icon: '/app-icons/icon-192.png',
        badge: '/app-icons/badge-72.png',
        data: { url: '/attendance' },
      });
    } catch (e) {
      // A notification is a courtesy; the punches are already sent.
    }
  }
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
      icon: '/app-icons/icon-192.png',
      badge: '/app-icons/badge-72.png',
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
