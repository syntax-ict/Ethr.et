<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The tenant-editable e-mail templates, and the one place they are rendered.
 *
 * Settings → Notification Templates has let a tenant admin edit six e-mails
 * since it shipped, storing the text in `tenants.settings.notification_templates`.
 * Nothing read it: every notification built its mail from hard-coded lines, so
 * a saved template changed no e-mail at all (audit N7). This class is now the
 * single source for both halves — the settings page lists these defaults and
 * variables, and the six notifications render through `mail()`.
 *
 * ## Shape
 *
 * Each type has a subject and a body per locale (`en`, `am`). A tenant may
 * override any of the four fields; a field it has not overridden (or left
 * empty) falls back to the built-in text below, field by field. The locale is
 * the application locale at render time — the same locale `__()` would use in
 * the notification, which is how these e-mails chose their language before.
 *
 * ## Placeholders
 *
 * `{name}` (or `{{name}}`, which admins type by habit) is replaced with the
 * value of a variable that type defines in `VARIABLES`. A placeholder the type
 * does not define is left exactly as written — predictable, and visible to the
 * admin who mistyped it. The settings endpoint refuses to save one, so only a
 * template stored before that check existed can carry one. Substitution is a
 * single pass: a value that itself contains `{date}` is not substituted again.
 *
 * ## Escaping
 *
 * The rendered text is treated as plain text, both the tenant-authored template
 * and the values (employee names, rejection reasons) dropped into it. Each body
 * line goes to the mail as an `HtmlString` whose HTML- and Markdown-significant
 * characters are already entities, so the Markdown mail layout can neither emit
 * a tag nor turn `[text](url)` into a link. The plain-text part decodes the
 * entities back. Subjects are headers, not HTML: control characters and line
 * breaks are collapsed to spaces.
 *
 * ## Failure
 *
 * Rendering never throws. A missing tenant, a malformed stored template, or a
 * template that cannot be substituted falls back to the built-in text and is
 * logged — a notification must not be lost to a settings typo.
 *
 * The tenant is looked up by the id the caller states, taken from the
 * tenant-owned row the e-mail is about. `Tenant` is on the Global Model List,
 * so the lookup works the same with no tenant resolved, as in a queue worker.
 */
final class NotificationTemplates
{
    public const LOCALES = ['en', 'am'];

    public const FIELDS = ['subject_en', 'subject_am', 'body_en', 'body_am'];

    /**
     * Built-in text per type. This is what is sent when a tenant has not
     * customised a field, and what the settings page shows as the starting point.
     */
    public const DEFAULTS = [
        'leave_requested' => [
            'subject_en' => 'New leave request from {employee_name}',
            'subject_am' => 'አዲስ የፈቃድ ጥያቄ — {employee_name}',
            'body_en' => '{employee_name} has submitted a {leave_type} request from {start_date} to {end_date} ({days} days). It is waiting for your approval.',
            'body_am' => '{employee_name} ከ{start_date} እስከ {end_date} ({days} ቀናት) {leave_type} ጥያቄ አቅርበዋል። ፈቃድዎ ያስፈልጋል።',
        ],
        'leave_approved' => [
            'subject_en' => 'Your leave request has been approved',
            'subject_am' => 'የፈቃድ ጥያቄዎ ተፈቅዷል',
            'body_en' => 'Your {leave_type} request from {start_date} to {end_date} has been approved.',
            'body_am' => 'ከ{start_date} እስከ {end_date} ያቀረቡት {leave_type} ጥያቄ ተፈቅዷል።',
        ],
        'leave_rejected' => [
            'subject_en' => 'Your leave request has been rejected',
            'subject_am' => 'የፈቃድ ጥያቄዎ ውድቅ ተደርጓል',
            'body_en' => "Your {leave_type} request from {start_date} to {end_date} has been rejected.\nReason: {reason}",
            'body_am' => "ከ{start_date} እስከ {end_date} ያቀረቡት {leave_type} ጥያቄ ውድቅ ተደርጓል።\nምክንያት: {reason}",
        ],
        'payslip_available' => [
            'subject_en' => 'Your payslip for {period} is ready',
            'subject_am' => 'የደመወዝ ሰነድዎ ዝግጁ ነው — {period}',
            'body_en' => 'Your payslip for {period} is now available. Log in to view and download it.',
            'body_am' => 'ለ{period} የደመወዝ ሰነድዎ አሁን ዝግጁ ነው። ለማየት እና ለማውረድ ይግቡ።',
        ],
        'missing_punch' => [
            'subject_en' => 'Missing {punch_type} — {date}',
            'subject_am' => 'የ{punch_type} ምዝገባ ጉድለት — {date}',
            'body_en' => "{employee_name} is missing a {punch_type} for {date}.\nReview the attendance record and request a correction if needed.",
            'body_am' => "{employee_name}፦ ለ{date} የ{punch_type} ምዝገባ የለም።\nእባክዎ የሰዓት መዝገቡን ይመልከቱ፤ አስፈላጊ ከሆነ ማስተካከያ ይጠይቁ።",
        ],
        'trial_expiring' => [
            'subject_en' => 'Your ETHR trial is expiring soon',
            'subject_am' => 'የETHR የሙከራ ጊዜዎ ሊያበቃ ነው',
            'body_en' => "Your ETHR trial will expire in {days_remaining} day(s) on {trial_ends_at}.\nUpgrade now to ensure uninterrupted access for your team.",
            'body_am' => "የETHR የሙከራ ጊዜዎ በ{days_remaining} ቀን(ቀናት) ውስጥ፣ {trial_ends_at} ላይ ያበቃል።\nቡድንዎ ያለማቋረጥ እንዲጠቀም አሁን ያሻሽሉ።",
        ],
    ];

    /**
     * The variables each type provides, in display order. Every notification
     * supplies every variable listed for its type (`organization_name` is added
     * here, from the tenant); a template may use any of them, in any field.
     */
    public const VARIABLES = [
        'leave_requested' => ['employee_name', 'leave_type', 'start_date', 'end_date', 'days', 'organization_name'],
        'leave_approved' => ['employee_name', 'leave_type', 'start_date', 'end_date', 'days', 'organization_name'],
        'leave_rejected' => ['employee_name', 'leave_type', 'start_date', 'end_date', 'days', 'reason', 'organization_name'],
        'payslip_available' => ['employee_name', 'period', 'net_amount', 'organization_name'],
        'missing_punch' => ['employee_name', 'date', 'punch_type', 'organization_name'],
        'trial_expiring' => ['days_remaining', 'trial_ends_at', 'organization_name'],
    ];

    private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}|\{\s*([A-Za-z0-9_]+)\s*\}/u';

    /**
     * Characters that would let text become markup once the Markdown mail
     * layout parses it. Entities are literal text to CommonMark, so none of
     * these can open a tag, a link, an image or a code span. Emphasis and
     * headings are left alone: they change how text looks, not where it leads.
     */
    private const ESCAPES = [
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        '[' => '&#91;',
        ']' => '&#93;',
        '\\' => '&#92;',
        '`' => '&#96;',
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * The variables a type provides; empty for an unknown type.
     *
     * @return list<string>
     */
    public static function variables(string $type): array
    {
        return self::VARIABLES[$type] ?? [];
    }

    public static function exists(string $type): bool
    {
        return isset(self::DEFAULTS[$type]);
    }

    /**
     * The fields a tenant has overridden for a type: non-empty strings only,
     * anything else in the stored JSON is ignored.
     *
     * @return array<string, string>
     */
    public static function customFor(?Tenant $tenant, string $type): array
    {
        // Read through getAttribute(): the column is JSON cast to an array, but a
        // value written around the cast (or by hand) must not break a mail.
        $settings = $tenant?->getAttribute('settings');
        $stored = is_array($settings) ? ($settings['notification_templates'][$type] ?? null) : null;

        if (! is_array($stored)) {
            return [];
        }

        $custom = [];
        foreach (self::FIELDS as $field) {
            $value = $stored[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $custom[$field] = $value;
            }
        }

        return $custom;
    }

    /**
     * Placeholders in `$text` that `$type` does not provide, as written.
     *
     * @return list<string>
     */
    public static function unknownPlaceholders(string $type, string $text): array
    {
        $known = self::VARIABLES[$type] ?? [];

        if (preg_match_all(self::PLACEHOLDER, $text, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $unknown = [];
        foreach ($matches as $match) {
            $name = self::placeholderName($match);
            if (! in_array($name, $known, true)) {
                $unknown[] = $match[0];
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * The subject and plain-text body for a type, in the current locale, with
     * the tenant's override where it has one.
     *
     * @param  array<string, scalar|null>  $values
     * @return array{subject: string, body: string}
     */
    public static function render(string $type, int|string|null $tenantId, array $values): array
    {
        if (! self::exists($type)) {
            throw new \InvalidArgumentException("Unknown notification template '{$type}'.");
        }

        // A driver may hand the key back as a numeric string (MariaDB under PDO).
        $tenantId = is_numeric($tenantId) ? (int) $tenantId : null;
        $locale = app()->getLocale() === 'am' ? 'am' : 'en';
        $subjectField = "subject_{$locale}";
        $bodyField = "body_{$locale}";

        $tenant = self::tenant($tenantId, $type);
        $custom = self::customFor($tenant, $type);
        $values['organization_name'] ??= $tenant?->name;

        return [
            'subject' => self::subjectLine(self::field($type, $subjectField, $custom, $values, $tenantId)),
            'body' => self::field($type, $bodyField, $custom, $values, $tenantId),
        ];
    }

    /**
     * A mail message carrying the rendered subject and body. Callers chain
     * their own action button onto it.
     *
     * @param  array<string, scalar|null>  $values
     */
    public static function mail(string $type, int|string|null $tenantId, array $values): MailMessage
    {
        $rendered = self::render($type, $tenantId, $values);

        $mail = (new MailMessage)->subject($rendered['subject']);

        foreach (self::bodyLines($rendered['body']) as $line) {
            $mail->line($line);
        }

        return $mail;
    }

    /**
     * One paragraph per non-empty line, each escaped for the Markdown layout.
     *
     * @return list<HtmlString>
     */
    public static function bodyLines(string $body): array
    {
        $lines = preg_split('/\R/u', $body) ?: [$body];

        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = new HtmlString(strtr($line, self::ESCAPES));
            }
        }

        return $out;
    }

    /**
     * The variable name in a placeholder match: group 1 for `{{name}}`, group 2
     * for `{name}`. PCRE reports the group that did not take part as `'`.
     *
     * @param  array<int|string, string>  $match
     */
    private static function placeholderName(array $match): string
    {
        $double = $match[1] ?? '';

        return $double !== '' ? $double : ($match[2] ?? '');
    }

    private static function tenant(?int $tenantId, string $type): ?Tenant
    {
        if ($tenantId === null) {
            return null;
        }

        try {
            return Tenant::query()->find($tenantId);
        } catch (Throwable $e) {
            Log::warning('Notification template: tenant could not be loaded; using the built-in text', [
                'type' => $type,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, string>  $custom
     * @param  array<string, scalar|null>  $values
     */
    private static function field(string $type, string $field, array $custom, array $values, ?int $tenantId): string
    {
        $default = self::DEFAULTS[$type][$field];

        if (isset($custom[$field])) {
            $rendered = self::substitute($type, $custom[$field], $values);
            if ($rendered !== null) {
                return $rendered;
            }

            Log::warning('Notification template could not be rendered; using the built-in text', [
                'type' => $type,
                'field' => $field,
                'tenant_id' => $tenantId,
            ]);
        }

        return self::substitute($type, $default, $values) ?? $default;
    }

    /**
     * Replace known placeholders in one pass. Null when the template cannot be
     * processed (invalid UTF-8 makes PCRE give up).
     *
     * @param  array<string, scalar|null>  $values
     */
    private static function substitute(string $type, string $template, array $values): ?string
    {
        $known = self::VARIABLES[$type];

        $result = preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($known, $values): string {
            $name = self::placeholderName($match);

            if (! in_array($name, $known, true)) {
                return $match[0];
            }

            return self::value($values[$name] ?? null);
        }, $template);

        if ($result === null) {
            return null;
        }

        // Control characters other than line breaks have no business in a mail.
        return preg_replace('/[^\P{Cc}\n]+/u', '', str_replace(["\r\n", "\r"], "\n", $result)) ?? $result;
    }

    /** A variable's value as a single line of text. */
    private static function value(mixed $value): string
    {
        if ($value === null || ! is_scalar($value)) {
            return '';
        }

        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return trim(preg_replace('/\p{Cc}+/u', ' ', $text) ?? '');
    }

    private static function subjectLine(string $subject): string
    {
        $line = trim(preg_replace('/[\s\p{Cc}]+/u', ' ', $subject) ?? '');

        return mb_substr($line, 0, 250);
    }
}
