<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Support\PhotoConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in employee's consent to the attendance selfie: read it, give
 * it, withdraw it. Only ever their own; nobody can consent for someone else.
 * Withdrawing stops new selfies from then on; photos already taken stay with
 * their attendance records, and erasing those is a separate request to the
 * organisation (Proclamation 1321/2024, Art. 28).
 */
class PhotoConsentController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(PhotoConsent::state($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        PhotoConsent::give($request->user());

        return response()->json(PhotoConsent::state($request->user()->refresh()));
    }

    public function destroy(Request $request): JsonResponse
    {
        PhotoConsent::withdraw($request->user());

        return response()->json(PhotoConsent::state($request->user()->refresh()));
    }
}
