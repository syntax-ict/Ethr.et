<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\LoginHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use App\Support\FrontendUrl;
use App\Support\TenancyDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(LoginRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $handOff = $this->canonicalAddressElsewhere($request, $currentTenant->get());
        if ($handOff !== null) {
            return $this->signInElsewhere($handOff);
        }

        $request->authenticate();

        /** @var User $user */
        $user = Auth::user();

        if ($user->status !== 'active') {
            Auth::guard('web')->logout();

            AuditLog::record('user.login_blocked', $user, [
                'reason' => 'account_inactive',
            ]);

            LoginHistory::record($user, 'blocked', 'account_inactive');

            return response()->json([
                'type' => 'https://ethr.et/errors/account-inactive',
                'title' => 'Account Inactive',
                'status' => 403,
                'detail' => __('auth.account_suspended'),
            ], 403);
        }

        $result = $this->authService->login($user);

        LoginHistory::record($user, 'success');

        return response()->json($result);
    }

    /**
     * The sign-in URL at the organisation's canonical address, when this
     * request reached it somewhere else this deployment owns; otherwise null.
     *
     * One address per organisation (owner decision 2026-10-08). An organisation
     * with a verified custom domain, or a subdomain while those are served, is
     * signed in there and nowhere else: the session cookie is host-only, so a
     * session started on the apex would not exist at the address every link
     * points to. Checked BEFORE the credentials, so the answer reveals nothing
     * the entry URL `ethr.et/{slug}` does not already redirect to.
     *
     * Only hosts under APP_DOMAIN hand off. A host the deployment does not own
     * (localhost behind a dev proxy, an IP) is left alone: it cannot be the
     * canonical address, so handing off from it would loop.
     */
    private function canonicalAddressElsewhere(LoginRequest $request, ?Tenant $tenant): ?string
    {
        $root = TenancyDomain::root();
        if ($tenant === null || $root === null) {
            return null;
        }

        $origin = FrontendUrl::canonicalOrigin($tenant);
        if ($origin === null) {
            return null;
        }

        $host = Tenant::normaliseDomain($request->getHost());
        $ownedHere = $host === $root || ($host !== null && str_ends_with($host, '.'.$root));
        if (! $ownedHere || $host === Tenant::normaliseDomain($origin)) {
            return null;
        }

        return $origin.'/login';
    }

    /**
     * 409 with the address to sign in at, which the sign-in page follows.
     */
    private function signInElsewhere(string $url): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/canonical-address',
            'title' => 'Sign In At Your Organisation\'s Address',
            'status' => 409,
            'detail' => __('auth.canonical_address'),
            'canonical_url' => $url,
        ], 409);
    }
}
