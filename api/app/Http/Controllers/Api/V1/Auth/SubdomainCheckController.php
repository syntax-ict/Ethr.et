<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SubdomainCheckRequest;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

class SubdomainCheckController extends Controller
{
    public function __invoke(SubdomainCheckRequest $request): JsonResponse
    {
        $subdomain = $request->input('subdomain');

        // Must agree with what registration enforces and what ResolveTenant will
        // actually resolve — hence the shared constant rather than a local list.
        // The local copy this replaced had already drifted: it advertised `cdn`
        // and `status` as available while resolution refused them.
        $available = ! in_array($subdomain, Tenant::RESERVED_SUBDOMAINS, true)
            && ! Tenant::withoutGlobalScopes()->where('subdomain', $subdomain)->exists();

        return response()->json([
            'subdomain' => $subdomain,
            'available' => $available,
        ]);
    }
}
