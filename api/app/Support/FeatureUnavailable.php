<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Feature;
use App\Enums\FeatureReason;
use Illuminate\Http\JsonResponse;

/**
 * The one answer for a request a feature switch refused, so the screen can
 * tell it from every other 403 by `code` and say why from `reason`
 * (docs/FEATURE-CONTROL-CENTER-DESIGN.md §5). `detail` is a sentence a screen
 * shows as it is: a platform switch says it is temporary, so nobody goes
 * looking for a setting of their own to change.
 *
 * A device is answered 503 with Retry-After instead: it buffers and tries
 * again, where a 403 would teach it to drop what it holds.
 */
final class FeatureUnavailable
{
    /** How long a device is asked to wait before it sends again. */
    public const RETRY_AFTER_SECONDS = 600;

    public static function response(Feature $feature, FeatureReason $reason, int $status = 403): JsonResponse
    {
        $response = response()->json([
            'type' => 'https://ethr.et/errors/feature-unavailable',
            'title' => 'Feature Unavailable',
            'status' => $status,
            'detail' => __($reason === FeatureReason::PlatformDisabled ? 'billing.feature_paused' : 'billing.feature_unavailable', [
                'feature' => __('billing.features.'.$feature->value),
            ]),
            'code' => 'feature-unavailable',
            'reason' => $reason->value,
        ], $status)->withHeaders(['Content-Type' => 'application/problem+json']);

        if ($status === 503) {
            $response->headers->set('Retry-After', (string) self::RETRY_AFTER_SECONDS);
        }

        return $response;
    }
}
