<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationTemplateRequest;
use App\Models\AuditLog;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class NotificationTemplateController extends Controller
{
    private const DEFAULTS = [
        'leave_requested' => [
            'subject_en' => 'New leave request pending your approval',
            'subject_am' => 'አዲስ የፈቃድ ጥያቄ ለአፈቃደቻ',
            'body_en' => '{employee_name} has submitted a {leave_type} request from {start_date} to {end_date} ({days} days).',
            'body_am' => '{employee_name} ከ{start_date} እስከ {end_date} ({days} ቀናት) {leave_type} ጥያቄ አቅርበዋል።',
        ],
        'leave_approved' => [
            'subject_en' => 'Your leave request has been approved',
            'subject_am' => 'የፈቃድ ጥያቄዎ ፀደቀ',
            'body_en' => 'Your {leave_type} request from {start_date} to {end_date} has been approved.',
            'body_am' => 'ከ{start_date} እስከ {end_date} {leave_type} ጥያቄዎ ፀድቋል።',
        ],
        'leave_rejected' => [
            'subject_en' => 'Your leave request has been rejected',
            'subject_am' => 'የፈቃድ ጥያቄዎ ተቀባዩ አልተቀበለም',
            'body_en' => 'Your {leave_type} request was rejected. Reason: {reason}.',
            'body_am' => '{leave_type} ጥያቄዎ ተቀባዩ አልተቀበለም። ምክንያት: {reason}።',
        ],
        'payslip_available' => [
            'subject_en' => 'Your payslip is available',
            'subject_am' => 'የደሞዝ ወረቀትዎ ዝግጁ ነው',
            'body_en' => 'Your payslip for {period} is available. Net pay: {net_amount}.',
            'body_am' => 'ለ{period} የደሞዝ ወረቀትዎ ዝግጁ ነው። ተወርዳ ደሞዝ: {net_amount}።',
        ],
        'missing_punch' => [
            'subject_en' => 'Missing attendance punch',
            'subject_am' => 'ያጡ የሂደት ምልክት',
            'body_en' => 'A missing {punch_type} was detected for {date}. Please submit a correction.',
            'body_am' => 'ለ{date} የጎደለ {punch_type} ተገኝቷል። እባክዎ ማስተካከያ ያቅርቡ።',
        ],
        'trial_expiring' => [
            'subject_en' => 'Your ETHR trial is expiring soon',
            'subject_am' => 'ነፃ ሞክሮዎ ቀርቧል',
            'body_en' => 'Your ETHR trial expires in {days_remaining} days on {trial_ends_at}. Upgrade to continue.',
            'body_am' => 'ነፃ ሞክሮዎ ከ{days_remaining} ቀናት በኋላ ይጠናቀቃል።',
        ],
    ];

    public function index(): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = app(CurrentTenant::class)->get();
        $customTemplates = $tenant->settings['notification_templates'] ?? [];

        $templates = collect(self::DEFAULTS)->map(function ($default, $type) use ($customTemplates) {
            $custom = $customTemplates[$type] ?? [];

            return [
                'type' => $type,
                'subject_en' => $custom['subject_en'] ?? $default['subject_en'],
                'subject_am' => $custom['subject_am'] ?? $default['subject_am'],
                'body_en' => $custom['body_en'] ?? $default['body_en'],
                'body_am' => $custom['body_am'] ?? $default['body_am'],
                'is_customized' => ! empty($custom),
                'variables' => $this->extractVariables($default['body_en']),
            ];
        });

        return response()->json(['templates' => $templates->values()]);
    }

    public function update(UpdateNotificationTemplateRequest $request, string $type): JsonResponse
    {
        Gate::authorize('settings.manage');

        if (! isset(self::DEFAULTS[$type])) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'Template Not Found',
                'status' => 404,
                'detail' => "No template with type '{$type}'",
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        $tenant = app(CurrentTenant::class)->get();
        $settings = $tenant->settings ?? [];
        $settings['notification_templates'][$type] = array_filter($request->only([
            'subject_en', 'subject_am', 'body_en', 'body_am',
        ]));

        $tenant->update(['settings' => $settings]);

        AuditLog::record('settings.notification_template_updated', $tenant, ['type' => $type]);

        return response()->json(['message' => 'Template updated.', 'type' => $type]);
    }

    /** @return string[] */
    private function extractVariables(string $template): array
    {
        preg_match_all('/\{(\w+)\}/', $template, $matches);

        return $matches[1] ?? [];
    }
}
