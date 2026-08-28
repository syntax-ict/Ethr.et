/* eslint-disable @typescript-eslint/no-explicit-any */

// laravel-echo and pusher-js are optional runtime dependencies loaded dynamically.
// Types are declared loosely to avoid tsc errors when they are not yet installed.

import { apiClient } from "@/api/client";

let echoInstance: any = null;

export function getEcho(): any {
  if (typeof window === "undefined") return null;
  return echoInstance;
}

export async function initEcho(): Promise<any> {
  if (typeof window === "undefined") return null;

  const reverbHost = process.env.NEXT_PUBLIC_REVERB_HOST ?? "localhost";
  const reverbPort = parseInt(
    process.env.NEXT_PUBLIC_REVERB_PORT ?? "8080",
    10,
  );
  const reverbScheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "http";
  const appKey = process.env.NEXT_PUBLIC_REVERB_APP_KEY ?? "ethr-key";

  try {
    const [{ default: LaravelEcho }, { default: Pusher }] = await Promise.all([
      import("laravel-echo" as any),
      import("pusher-js" as any),
    ]);

    (window as any).Pusher = Pusher;

    echoInstance = new LaravelEcho({
      broadcaster: "reverb",
      key: appKey,
      wsHost: reverbHost,
      wsPort: reverbPort,
      wssPort: reverbPort,
      forceTLS: reverbScheme === "https",
      enabledTransports: ["ws", "wss"],
      authEndpoint: "/api/v1/broadcasting/auth",
      auth: {
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
      },
      // Authorized through `apiClient`, not a bare `fetch`.
      //
      // `bootstrap/app.php` calls `$middleware->statefulApi()`, so a
      // cookie-authenticated POST to /api/v1/* is CSRF-validated. The previous
      // hand-rolled fetch sent `credentials: "include"` but no `X-XSRF-TOKEN`,
      // so *every* private-channel subscription was rejected with 419 and
      // silently swallowed by the catch below — in-app realtime notifications
      // and live device status never worked in any environment. axios reads the
      // XSRF-TOKEN cookie and sets that header itself, and the shared instance
      // also carries the tenant header and the 401 refresh interceptor.
      authorizer: (channel: any) => ({
        authorize: (socketId: string, callback: any) => {
          apiClient
            .post("/broadcasting/auth", {
              socket_id: socketId,
              channel_name: channel.name,
            })
            .then((res) => callback(null, res.data))
            .catch((err) => callback(err));
        },
      }),
    });

    return echoInstance;
  } catch {
    return null;
  }
}

export function disconnectEcho(): void {
  if (echoInstance) {
    echoInstance.disconnect();
    echoInstance = null;
  }
}
