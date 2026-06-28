<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTrialExpiration
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->currentTenant->get();

        if ($tenant && $tenant->isTrialExpired()) {
            return response()->json([
                'type' => 'https://ethr.et/errors/trial-expired',
                'title' => __('general.trial_expired'),
                'status' => 402,
                'detail' => __('general.trial_expired_detail'),
            ], 402);
        }

        return $next($request);
    }
}
