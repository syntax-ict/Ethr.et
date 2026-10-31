// ETHR Service Worker — offline-first for Ethiopian field workers
// Version is baked in at build time via build process / cache-busting
// v2 (2026-10-12): bumping it makes activate delete every v1 cache, which is
// how responses stored before the prefixes below were excluded leave devices.
// v3 (2026-10-28): the shell pages are stored with the scripts and styles they
// load. v2 stored only their HTML, so /offline came out of the cache and then
// failed on a chunk nothing had fetched (production QA 2026-10-28, F-01).
// v4 (2026-10-30): the installed app could not start offline. Its start URL,
// /dashboard?source=pwa, was never stored, and the fallback matched the exact
// URL, query string included, so a cold start offline always got /offline.
// Pages are now kept under their path alone, visited pages included.
// v5 (2026-10-31): pay data is no longer stored (NEVER_CACHED_API), and this
// removes the copies v4 already kept. Later the same day, stored API answers
// gained a one-week limit (API_MAX_AGE_MS); copies without a stamp count as
// expired, so no version change was needed to retire them.
const CACHE_VERSION = 'ethr-v5';
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
// Pay data joined them on 2026-10-31: payroll runs, other people's payslips,
// bank accounts, contracts and reports. A copy outlives a session that ends
// while the device is offline, and none of it is needed to punch in. Your own
// payslips stay (OFFLINE_PAY_API), since /payroll/payslips is an offline page.
const NEVER_CACHED_API = ['/api/v1/admin/', '/api/v1/auth/', '/api/v1/payroll/', '/api/v1/reports/'];
const NEVER_CACHED_API_PARTS = ['/bank-details', '/contracts'];
const OFFLINE_PAY_API = ['/api/v1/payroll/payslips/my'];

// How long a stored API answer may be shown offline. The worker cannot tell
// whether the session behind it has ended while the device has no signal, so
// the copy carries its own limit: a week covers a field trip with no network,
// and a lost or shared phone stops showing someone's records after it. Older
// copies are deleted rather than served. Queued punches are not affected; they
// live in IndexedDB and are sent whenever the network returns.
const API_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;
const STORED_AT_HEADER = 'X-ETHR-Stored-At';
const SWEEP_EVERY_MS = 60 * 60 * 1000;
let lastSweep = 0;

function neverCached(pathname) {
  if (OFFLINE_PAY_API.some(path => pathname === path)) return false;

  return NEVER_CACHED_API.some(prefix => pathname.startsWith(prefix))
    || (pathname.startsWith('/api/') && NEVER_CACHED_API_PARTS.some(part => pathname.includes(part)));
}

// Shell assets to precache — these make the app load offline. /dashboard is
// the manifest's start_url and the rest after it are its shortcuts, so each
// way into the installed app opens with no signal on the first day.
const SHELL_ASSETS = [
  '/',
  '/offline',
  '/attendance/mobile',
  '/kiosk',
  '/dashboard',
  '/attendance',
  '/leave',
  '/payroll/payslips',
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

  if (neverCached(url.pathname)) return;

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

  // Next's client-side navigation reads a page as a .txt payload rather than
  // as HTML. Like the HTML, it is the static export, the same for everyone.
  if (isPagePayload(url)) {
    event.respondWith(networkFirstPayload(request, url));
    return;
  }

  // App shell pages: network-first, fall back to the stored copy, then /offline.
  // A page that opened online is kept (it is the static export, the same for
  // every user; the data comes from the API), so a page someone uses is there
  // the next time they open it with no signal.
  if (!url.pathname.includes('.')) {
    event.respondWith(
      fetch(request).then((response) => {
        if (request.mode === 'navigate' && response.ok) {
          event.waitUntil(keepPage(url, response.clone()).catch(() => {}));
          event.waitUntil(refreshShellIfStale().catch(() => {}));
        }
        return response;
      }).catch(() => cachedPage(url))
    );
    return;
  }
});

// ── Shell: each page together with the files it loads ───────────────────────
// Pages are stored under their path alone. The installed app opens
// /dashboard?source=pwa and its shortcuts add ?source=shortcut, so a copy keyed
// on the full URL was never found offline.
function pageKey(url) {
  const path = url.pathname.replace(/\/+$/, '');
  return path === '' ? '/' : path;
}

async function cachedPage(url) {
  const cached = await caches.match(pageKey(url), { ignoreSearch: true });
  return cached ?? caches.match('/offline');
}

async function keepPage(url, response) {
  const cache = await caches.open(STATIC_CACHE);
  await storePage(cache, pageKey(url), response);
}

async function cacheShell() {
  const cache = await caches.open(STATIC_CACHE);
  await cache.put(SHELL_STAMP, new Response(String(Date.now())));
  await Promise.allSettled(SHELL_ASSETS.map(url => cacheShellPage(cache, url)));
}

async function cacheShellPage(cache, url) {
  const response = await fetch(url, { cache: 'no-store' });
  await storePage(cache, url, response);
}

// The page is stored only once every script and stylesheet it names is, so a
// copy that would render and then fail to hydrate never replaces a working one.
// A redirected answer is not stored: a browser refuses one for a navigation.
async function storePage(cache, key, response) {
  if (!response.ok || response.redirected) return;

  const html = await response.clone().text();
  const assets = [...new Set(html.match(/\/_next\/static\/[^"'\s\\)]+/g) || [])];
  const stored = await Promise.allSettled(assets.map(async (asset) => {
    if (!(await cache.match(asset))) await cache.add(asset);
  }));

  if (stored.every(result => result.status === 'fulfilled')) {
    await cache.put(key, response);
  }
}

// /dashboard/__next.!KGRhc2hib2FyZCk.dashboard.__PAGE__.txt (a segment
// prefetch) or /dashboard.txt?_rsc=… (a full payload).
function isPagePayload(url) {
  return url.pathname.endsWith('.txt') &&
    (url.pathname.includes('/__next.') || url.searchParams.has('_rsc'));
}

// Stored under the path: the _rsc value changes, the file does not. With no
// copy, the request fails as it would without this worker, and Next then
// loads the page as a whole, which the navigation fallback above answers.
async function networkFirstPayload(request, url) {
  const cache = await caches.open(STATIC_CACHE);
  try {
    const response = await fetch(request);
    if (response.ok) cache.put(url.pathname, response.clone());
    return response;
  } catch (error) {
    const cached = await cache.match(url.pathname);
    if (cached) return cached;
    throw error;
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
  if (Date.now() - lastSweep > SWEEP_EVERY_MS) {
    lastSweep = Date.now();
    sweepExpired(cache);
  }
  try {
    const response = await fetch(request);
    if (response.ok) cache.put(request, await stamped(response.clone()));
    // The session is over, so nothing stored under it may be served offline
    // afterwards. The app clears the cache on a 401 it sees itself; this also
    // covers requests the worker makes on its own.
    if (response.status === 401) await caches.delete(cacheName);
    return response;
  } catch {
    const cached = await cache.match(request);
    if (cached && !expired(cached)) return cached;
    if (cached) await cache.delete(request);
    return new Response(
      JSON.stringify({ error: 'offline', message: 'No network connection' }),
      { status: 503, headers: { 'Content-Type': 'application/json' } }
    );
  }
}

// A copy of the response that records when it was stored.
async function stamped(response) {
  const headers = new Headers(response.headers);
  headers.set(STORED_AT_HEADER, String(Date.now()));
  return new Response(await response.arrayBuffer(), {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}

// A copy with no stamp was stored before the limit existed, so its age is
// unknown and it counts as expired.
function expired(response) {
  const storedAt = Number(response.headers.get(STORED_AT_HEADER));
  return !storedAt || Date.now() - storedAt > API_MAX_AGE_MS;
}

// Deletes every expired copy, not only the ones someone asks for again.
async function sweepExpired(cache) {
  try {
    for (const request of await cache.keys()) {
      const response = await cache.match(request);
      if (response && expired(response)) await cache.delete(request);
    }
  } catch {
    // Cache Storage refused; the next sweep tries again.
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
