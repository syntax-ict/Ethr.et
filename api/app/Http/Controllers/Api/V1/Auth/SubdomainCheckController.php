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
        $reserved = ['www', 'api', 'admin', 'mail', 'smtp', 'ftp', 'app', 'staging', 'dev', 'test'];
        $subdomain = $request->input('subdomain');

        $available = ! in_array($subdomain, $reserved, true)
            && ! Tenant::withoutGlobalScopes()->where('subdomain', $subdomain)->exists();

        return response()->json([
            'subdomain' => $subdomain,
            'available' => $available,
        ]);
    }
}
