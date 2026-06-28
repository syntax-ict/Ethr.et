<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    public function __invoke(ContactRequest $request): JsonResponse
    {
        $data = $request->validated();

        Log::channel('stack')->info('Contact form submission', $data);

        return response()->json([
            'message' => __('general.created', ['resource' => 'Contact request']),
        ], 201);
    }
}
