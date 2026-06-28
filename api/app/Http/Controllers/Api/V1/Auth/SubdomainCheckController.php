<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubdomainCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'subdomain' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/'],
        ]);

        $reserved = ['www', 'api', 'admin', 'mail', 'smtp', 'ftp', 'app', 'staging', 'dev', 'test'];
        $subdomain = $request->input('subdomain');

        $available = !in_array($subdomain, $reserved, true)
            && !Tenant::withoutGlobalScopes()->where('subdomain', $subdomain)->exists();

        return response()->json([
            'subdomain' => $subdomain,
            'available' => $available,
        ]);
    }
}
