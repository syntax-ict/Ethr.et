import type { MeResponse } from "./api";

/**
 * Who was last signed in on this device, kept so the installed app can open
 * with no signal.
 *
 * A field worker opens ETHR where there is no coverage, to punch in. /auth/me
 * cannot answer, and the service worker never stores it (console QA F3), so
 * the dashboard had nobody to show and sent them to /login, which cannot sign
 * anyone in offline either. With this, `meQueryOptions` answers from the last
 * identity when the server cannot be reached, and the punch is queued under
 * the right employee.
 *
 * The privacy choice. Only what the shell and the punch screen need to render
 * is kept: the name, the role and permissions that decide the menu, the
 * employee record a queued punch is filed under, the language and calendar,
 * and the organisation's name, zone and colours. Not the email address, phone
 * number, photo or sign-in history. Nothing here is a credential: the session
 * stays in its HttpOnly cookie, so this cannot sign anyone in and every request
 * still needs that cookie. It is in localStorage, not Cache Storage, so it is
 * never served in answer to a request. It is removed on sign-out and whenever
 * the server answers 401. What remains is that a person who picks up the phone
 * while it is offline sees whose app it is until it reaches the server; that is
 * the cost of the app opening offline at all.
 */
const KEY = "ethr.offline-identity";

interface StoredIdentity {
  v: 1;
  user: Pick<
    MeResponse["user"],
    | "public_id"
    | "name"
    | "name_am"
    | "role"
    | "status"
    | "mfa_enabled"
    | "locale"
    | "preferences"
    | "employee_code"
    | "employee_public_id"
  >;
  permissions: string[];
  plan_features: string[] | null;
  tenant: Pick<
    NonNullable<MeResponse["tenant"]>,
    "public_id" | "name" | "subdomain" | "status" | "timezone" | "theme"
  > | null;
}

export function saveOfflineIdentity(me: MeResponse): void {
  const { user, tenant } = me;
  if (!user?.public_id) return;

  const identity: StoredIdentity = {
    v: 1,
    user: {
      public_id: user.public_id,
      name: user.name ?? null,
      name_am: user.name_am ?? null,
      role: user.role,
      status: user.status,
      mfa_enabled: user.mfa_enabled,
      locale: user.locale,
      preferences: user.preferences,
      employee_code: user.employee_code ?? null,
      employee_public_id: user.employee_public_id ?? null,
    },
    permissions: me.permissions ?? [],
    plan_features: me.plan_features ?? null,
    tenant: tenant
      ? {
          public_id: tenant.public_id,
          name: tenant.name,
          subdomain: tenant.subdomain,
          status: tenant.status,
          timezone: tenant.timezone ?? null,
          theme: tenant.theme ?? null,
        }
      : null,
  };

  try {
    localStorage.setItem(KEY, JSON.stringify(identity));
  } catch {
    // Storage full or refused: the app still works online.
  }
}

/** The kept identity as an /auth/me answer, or null when there is none. */
export function readOfflineIdentity(): MeResponse | null {
  let identity: StoredIdentity;
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return null;
    identity = JSON.parse(raw);
  } catch {
    return null;
  }
  if (identity?.v !== 1 || !identity.user?.public_id) return null;

  return {
    user: {
      ...identity.user,
      email: "",
      phone: null,
      last_login_at: null,
      photo_thumb_url: null,
    },
    permissions: identity.permissions ?? [],
    plan_features: identity.plan_features ?? null,
    tenant: identity.tenant,
    from_device: true,
  };
}

export function clearOfflineIdentity(): void {
  try {
    localStorage.removeItem(KEY);
  } catch {
    // Nothing to remove, or the browser refused.
  }
}
