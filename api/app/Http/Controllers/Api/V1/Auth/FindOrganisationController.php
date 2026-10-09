<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FindOrganisationRequest;
use App\Notifications\OrganisationSignInLinksNotification;
use App\Services\Auth\OrganisationFinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class FindOrganisationController extends Controller
{
    /**
     * Email the sign-in links of every organisation this address belongs to.
     */
    public function __invoke(FindOrganisationRequest $request, OrganisationFinder $finder): JsonResponse
    {
        $email = (string) $request->validated('email');

        // Notes kept out of the docblock: Scramble publishes that as the
        // endpoint's public description.
        //
        // Rate-limited the way PasswordResetController::forgot() is: the route
        // sits in the `throttle:auth` group, and this per-address-and-IP
        // counter (3 per 5 minutes) bounds how fast one address can be probed
        // or one inbox flooded.
        $key = 'find-organisation|'.Str::lower($email).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/rate-limit',
                'title' => 'Too Many Requests',
                'status' => 429,
                'detail' => __('auth.find_organisation.too_many'),
            ], 429);
        }
        RateLimiter::hit($key, 300);

        // Which organisations an address belongs to goes to the inbox only; the
        // response below is the same sentence for none, one or many. (Kept
        // here rather than beside the return: Scramble publishes a comment
        // adjacent to the returned expression as the response's description.)
        $organisations = $finder->forEmail($email);
        if ($organisations !== []) {
            Notification::route('mail', $email)
                ->notify((new OrganisationSignInLinksNotification($organisations))->locale(app()->getLocale()));
        }

        return response()->json(['message' => __('auth.find_organisation.sent')]);
    }
}
