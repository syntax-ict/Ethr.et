import axios from "axios";
import { apiClient } from "@/api/client";

/**
 * Punching at a kiosk through a power cut or a dropped connection.
 *
 * While online the kiosk receives its public key and a grant (see
 * KioskOfflineSeal on the API). A punch made offline is sealed here with
 * RSA-OAEP under that key - employee code, PIN, type, time and idempotency
 * key together - and kept in IndexedDB until the connection returns. Only
 * the server can open it: nothing waiting on the terminal can be read or
 * changed there, the PIN included.
 */

export interface KioskOfflineConfig {
  public_key: string;
  grant: string;
  issued_at: string;
}

export interface QueuedKioskPunch {
  idempotency_key: string;
  sealed: string;
  grant: string | null;
  queued_at: string;
}

interface SealedPunch {
  /** Employee code. */
  c: string;
  /** PIN, when the organisation requires one. */
  p: string | null;
  t: "check_in" | "check_out";
  /** When the punch was made, by the terminal's clock. */
  at: string;
  /** Idempotency key. */
  k: string;
}

const DB_NAME = "ethr-kiosk";
const STORE = "punches";

function base64ToBytes(b64: string): Uint8Array<ArrayBuffer> {
  const raw = atob(b64);
  const bytes = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
  return bytes;
}

function bytesToBase64(bytes: ArrayBuffer): string {
  let raw = "";
  for (const b of new Uint8Array(bytes)) raw += String.fromCharCode(b);
  return btoa(raw);
}

/** Seal a punch with the kiosk's public key (RSA-OAEP, SHA-1, as the server opens it). */
export async function sealPunch(
  publicKeyB64: string,
  punch: SealedPunch,
): Promise<string> {
  const key = await crypto.subtle.importKey(
    "spki",
    base64ToBytes(publicKeyB64),
    { name: "RSA-OAEP", hash: "SHA-1" },
    false,
    ["encrypt"],
  );
  const cipher = await crypto.subtle.encrypt(
    { name: "RSA-OAEP" },
    key,
    new TextEncoder().encode(JSON.stringify(punch)),
  );
  return bytesToBase64(cipher);
}

function openDB(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => {
      if (!request.result.objectStoreNames.contains(STORE)) {
        request.result.createObjectStore(STORE, { keyPath: "idempotency_key" });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function withStore<T>(
  mode: IDBTransactionMode,
  run: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const request = run(db.transaction(STORE, mode).objectStore(STORE));
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

export function queuePunch(punch: QueuedKioskPunch): Promise<IDBValidKey> {
  return withStore("readwrite", (store) => store.put(punch));
}

export function queuedPunches(): Promise<QueuedKioskPunch[]> {
  return withStore("readonly", (store) => store.getAll());
}

export function dropPunch(idempotencyKey: string): Promise<undefined> {
  return withStore("readwrite", (store) => store.delete(idempotencyKey));
}

/** Seal and keep a punch made while the kiosk cannot reach the server. */
export async function savePunchOffline(
  offline: KioskOfflineConfig,
  punch: Omit<SealedPunch, "at">,
): Promise<void> {
  const sealed = await sealPunch(offline.public_key, {
    ...punch,
    at: new Date().toISOString(),
  });
  await queuePunch({
    idempotency_key: punch.k,
    sealed,
    grant: offline.grant,
    queued_at: new Date().toISOString(),
  });
}

interface SyncResult {
  idempotency_key: string;
  status: "created" | "duplicate" | "error";
}

/**
 * Send what waited. Every answered punch leaves the queue - recorded,
 * already recorded, or refused for good (a wrong PIN stays wrong); a punch
 * the server never answered stays for the next try.
 */
export async function syncQueuedPunches(token: string): Promise<number> {
  const queued = await queuedPunches();
  if (queued.length === 0) return 0;

  let sent = 0;
  for (let i = 0; i < queued.length; i += 50) {
    const batch = queued.slice(i, i + 50);
    const { data } = await apiClient.post<{ results: SyncResult[] }>(
      "/kiosk/sync",
      {
        records: batch.map(({ idempotency_key, sealed, grant }) => ({
          idempotency_key,
          sealed,
          grant,
        })),
      },
      { headers: { "X-Kiosk-Token": token }, skipAuthRefresh: true },
    );
    for (const result of data.results) {
      await dropPunch(result.idempotency_key);
      if (result.status !== "error") sent++;
    }
  }
  return sent;
}

/** A request that never got an answer: offline, or the server unreachable. */
export function isUnreachable(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response === undefined;
}

const CONFIG_KEY = "kiosk_config";

/** The kiosk's last configuration, so a restart without internet still opens. */
export function cacheKioskConfig<T extends object>(config: T): void {
  try {
    localStorage.setItem(CONFIG_KEY, JSON.stringify(config));
  } catch {
    // Storage full or blocked: the kiosk simply needs a connection to start.
  }
}

export function cachedKioskConfig<T>(token: string): T | null {
  try {
    const raw = localStorage.getItem(CONFIG_KEY);
    const parsed = raw ? (JSON.parse(raw) as T & { token?: string }) : null;
    return parsed && parsed.token === token ? parsed : null;
  } catch {
    return null;
  }
}

export function forgetKioskConfig(): void {
  try {
    localStorage.removeItem(CONFIG_KEY);
  } catch {
    // Nothing to forget.
  }
}
