<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationTemplateRequest;
use App\Models\AuditLog;
use App\Services\CurrentTenant;
use App\Support\NotificationTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class NotificationTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = app(CurrentTenant::class)->get();

        $templates = collect(NotificationTemplates::DEFAULTS)->map(function ($default, $type) use ($tenant) {
            $custom = NotificationTemplates::customFor($tenant, $type);

            return [
                'type' => $type,
                'subject_en' => $custom['subject_en'] ?? $default['subject_en'],
                'subject_am' => $custom['subject_am'] ?? $default['subject_am'],
                'body_en' => $custom['body_en'] ?? $default['body_en'],
                'body_am' => $custom['body_am'] ?? $default['body_am'],
                'is_customized' => ! empty($custom),
                'variables' => NotificationTemplates::variables($type),
            ];
        });

        return response()->json(['templates' => $templates->values()]);
    }

    public function update(UpdateNotificationTemplateRequest $request, string $type): JsonResponse
    {
        Gate::authorize('settings.manage');

        if (! NotificationTemplates::exists($type)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Template Not Found',
                'status' => 404,
                'detail' => "No template with type '{$type}'",
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        $tenant = app(CurrentTenant::class)->get();
        $settings = $tenant->settings ?? [];
        // A field saved unchanged from the built-in text is not an override: storing
        // it would pin the tenant to today's wording and mark the template customised.
        $settings['notification_templates'][$type] = array_filter(
            $request->only(NotificationTemplates::FIELDS),
            fn ($value, $field) => is_string($value) && trim($value) !== ''
                && $value !== NotificationTemplates::DEFAULTS[$type][$field],
            ARRAY_FILTER_USE_BOTH,
        );

        $tenant->update(['settings' => $settings]);

        AuditLog::record('settings.notification_template_updated', $tenant, ['type' => $type]);

        return response()->json(['message' => 'Template updated.', 'type' => $type]);
    }
}
