<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Auth\SessionCookie;
use App\Services\Auth\SessionHandoff;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tenant-host half of the impersonation handoff.
 *
 * admin.ethr.et mints the impersonation token and returns a nonce naming it;
 * the browser arrives here, on {tenant}.ethr.et, and exchanges that nonce for a
 * host-only session cookie. Nothing but the nonce crosses the host boundary,
 * and it is single-use and seconds-lived.
 *
 * Unauthenticated by necessity — the whole point is that the caller has no
 * session on this host yet. The nonce is the credential: 256 bits from a
 * CSPRNG, redeemable once, and only on the tenant it was issued for.
 */
class SessionClaimController extends Controller
{
    public function __invoke(
        Request $request,
        SessionHandoff $handoff,
        SessionCookie $sessionCookie,
        CurrentTenant $currentTenant,
    ): JsonResponse {
        $nonce = $request->input('nonce');

        // The tenant comes from the hostname, never from the request body: that
        // is what binds a nonce to the host it may be claimed on. A nonce issued
        // for habru presented to woldia.ethr.et must fail, and this is why.
        $tenant = $currentTenant->get();

        if (! is_string($nonce) || $nonce === '' || $tenant === null) {
            return $this->rejected();
        }

        $claimed = $handoff->claim($nonce, $tenant->subdomain);

        if ($claimed === null) {
            return $this->rejected();
        }

        $sessionCookie->issue($claimed['token'], $claimed['session_seconds']);

        AuditLog::record('admin.tenant.impersonation_handoff_claimed', $tenant, [
            'host' => $request->getHost(),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * One response for every failure mode.
     *
     * Unknown, expired, already-claimed and wrong-tenant are deliberately
     * indistinguishable — telling a caller which of those applied would let them
     * probe for live nonces, and there is nothing a legitimate client would do
     * differently in any of the four cases.
     */
    private function rejected(): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/invalid-handoff',
            'title' => 'Invalid Handoff',
            'status' => 422,
            'detail' => 'This sign-in link is no longer valid. Start the impersonation again.',
        ], 422)->withHeaders(['Content-Type' => 'application/problem+json']);
    }
}
