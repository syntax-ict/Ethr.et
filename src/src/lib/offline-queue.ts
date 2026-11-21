import { requestPersistentStorage } from "@/lib/persist-storage";

const DB_NAME = "ethr-offline";
const DB_VERSION = 1;
const STORE_NAME = "attendance_queue";

export interface OfflineAttendanceRecord {
  id?: number;
  employee_public_id: string;
  type: "check_in" | "check_out";
  idempotency_key: string;
  offline_token: string;
  latitude?: number | null;
  longitude?: number | null;
  /** GPS accuracy in metres, as the phone reported it. */
  accuracy?: number | null;
  /** The selfie, when the organisation requires one. */
  photo?: string | null;
  captured_at: string;
  /**
   * 0 waiting to be sent, 1 sent, 2 refused for good: the server will never
   * accept this punch, so it is no longer retried and is shown to the person
   * until they remove it. public/sw.js writes the same three values.
   */
  synced: 0 | 1 | 2;
  sync_error?: string | null;
  /**
   * Set on a punch that was refused without the server naming a reason (its
   * request was too large or invalid on its own). The screen then says why in
   * its own words, in the person's language, instead of showing `sync_error`.
   */
  refused_locally?: boolean;
  /**
   * The X-Tenant header to send with it, when the host does not name the
   * organisation. The service worker reads it (see public/sw.js).
   */
  tenant?: string | null;
  /** The interface language, for the service worker's "sent" notification. */
  locale?: string | null;
  /** Fix details and the install id, for the risk scoring (punch-signals). */
  altitude?: number;
  speed?: number;
  heading?: number;
  fix_age_ms?: number;
  device_id?: string;
  client_time?: string;
  webdriver?: boolean;
  /** The on-device liveness outcome, when the selfie was checked. */
  liveness?: string | null;
  /** The registered phone's signature over this punch (passkey.ts). */
  device_proof?: unknown;
}

function openDB(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        const store = db.createObjectStore(STORE_NAME, {
          keyPath: "id",
          autoIncrement: true,
        });
        store.createIndex("synced", "synced", { unique: false });
      }
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

export async function enqueueOfflineRecord(
  record: Omit<OfflineAttendanceRecord, "id" | "synced">,
): Promise<number> {
  requestPersistentStorage();
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, "readwrite");
    const store = tx.objectStore(STORE_NAME);
    const req = store.add({ ...record, synced: 0, sync_error: null });
    req.onsuccess = () => resolve(req.result as number);
    req.onerror = () => reject(req.error);
  });
}

async function recordsWithState(
  state: 0 | 2,
  employeePublicId?: string | null,
): Promise<OfflineAttendanceRecord[]> {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, "readonly");
    const store = tx.objectStore(STORE_NAME);
    const index = store.index("synced");
    const req = index.getAll(state);
    req.onsuccess = () => {
      const records = req.result as OfflineAttendanceRecord[];
      resolve(
        employeePublicId
          ? records.filter((r) => r.employee_public_id === employeePublicId)
          : records,
      );
    };
    req.onerror = () => reject(req.error);
  });
}

/**
 * Punches waiting to be sent. A shared phone can hold several people's: pass
 * the signed-in person's id to read only theirs.
 */
export function getPendingRecords(
  employeePublicId?: string | null,
): Promise<OfflineAttendanceRecord[]> {
  return recordsWithState(0, employeePublicId);
}

/** Punches the server refused for good and the person has not removed yet. */
export function getRefusedRecords(
  employeePublicId?: string | null,
): Promise<OfflineAttendanceRecord[]> {
  return recordsWithState(2, employeePublicId);
}

export async function getAllRecords(): Promise<OfflineAttendanceRecord[]> {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, "readonly");
    const store = tx.objectStore(STORE_NAME);
    const req = store.getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

export async function markSynced(id: number): Promise<void> {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, "readwrite");
    const store = tx.objectStore(STORE_NAME);
    const getReq = store.get(id);
    getReq.onsuccess = () => {
      const record = getReq.result;
      if (record) {
        record.synced = 1;
        record.sync_error = null;
        store.put(record);
      }
      resolve();
    };
    getReq.onerror = () => reject(getReq.error);
  });
}

export async function markSyncError(id: number, error: string): Promise<void> {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, "readwrite");
    const store = tx.objectStore(STORE_NAME);
    const getReq = store.get(id);
    getReq.onsuccess = () => {
      const record = getReq.result;
      if (record) {
        record.sync_error = error;
        store.put(record);
      }
      resolve();
    };
    getReq.onerror = () => reject(getReq.error);
  });
}

/**
 * Takes a punch out of the waiting list for good: it will never be sent again.
 * `reason` is the server's sentence; omit it for a punch refused here, and the
 * screen words it.
 */
export async function markRefused(
  id: number,
  reason?: string | null,
): Promise<void> {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, "readwrite");
    const store = tx.objectStore(STORE_NAME);
    const getReq = store.get(id);
    getReq.onsuccess = () => {
      const record = getReq.result;
      if (record) {
        record.synced = 2;
        record.sync_error = reason ?? null;
        record.refused_locally = !reason;
        // The refused card shows type, time and reason, never the selfie.
        record.photo = null;
        store.put(record);
      }
      resolve();
    };
    getReq.onerror = () => reject(getReq.error);
  });
}

/** Deletes one record, used when the person removes a refused punch. */
export async function removeRecord(id: number): Promise<void> {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, "readwrite");
    tx.objectStore(STORE_NAME).delete(id);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

export async function clearSyncedRecords(): Promise<void> {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, "readwrite");
    const store = tx.objectStore(STORE_NAME);
    const index = store.index("synced");
    const req = index.openCursor(1);
    req.onsuccess = () => {
      const cursor = req.result;
      if (cursor) {
        cursor.delete();
        cursor.continue();
      } else {
        resolve();
      }
    };
    req.onerror = () => reject(req.error);
  });
}

export async function getPendingCount(
  employeePublicId?: string | null,
): Promise<number> {
  const records = await getPendingRecords(employeePublicId);
  return records.length;
}
