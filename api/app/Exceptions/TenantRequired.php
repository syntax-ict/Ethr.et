<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An organisation's endpoint called with no organisation resolved.
 *
 * A platform administrator has no tenant, and on the platform host none ever
 * resolves. Nineteen controller reads did `CurrentTenant::get()->id`, so the
 * executive dashboard, analytics, reports and the rest answered 500 to every
 * platform sign-in, which lands on /dashboard (production, 2026-10-28). Thrown
 * by `CurrentTenant::requireId()` and rendered here as an RFC-7807 422, the
 * same type the onboarding endpoints already return for the same case.
 */
final class TenantRequired extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/tenant-required',
            'title' => 'Tenant Required',
            'status' => 422,
            'detail' => __('general.tenant_required'),
        ], 422, ['Content-Type' => 'application/problem+json']);
    }
}
