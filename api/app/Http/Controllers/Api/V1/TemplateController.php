<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganizationTemplate;
use Illuminate\Http\JsonResponse;

class TemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = OrganizationTemplate::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['public_id', 'name', 'slug', 'description', 'icon', 'template_data', 'sort_order']);

        return response()->json(['data' => $templates]);
    }

    public function show(string $slug): JsonResponse
    {
        $template = OrganizationTemplate::where('slug', $slug)
            ->where('is_active', true)
            ->first(['public_id', 'name', 'slug', 'description', 'icon', 'template_data']);

        if (! $template) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => __('general.not_found', ['resource' => 'Template']),
            ], 404);
        }

        return response()->json($template);
    }
}
