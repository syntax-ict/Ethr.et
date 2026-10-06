<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `PUT /settings/organization` used to write the tenant's timezone and locale
 * into the `settings` JSON column, while `tenants.timezone` and
 * `tenants.default_locale` — the columns TenantResource exposes and
 * UserProvisioningService reads when provisioning a user — kept their defaults.
 * The endpoint now writes the columns; this moves the values any tenant already
 * saved through the old path across, so nobody silently reverts to
 * Africa/Addis_Ababa / en.
 *
 * Only fills tenants whose column is still at its default and whose JSON holds
 * something else, so a tenant that set the column directly is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->select('id', 'settings', 'timezone', 'default_locale')
            ->whereNotNull('settings')
            ->orderBy('id')
            ->chunk(200, function ($tenants) {
                foreach ($tenants as $tenant) {
                    $settings = json_decode((string) $tenant->settings, true);

                    if (! is_array($settings)) {
                        continue;
                    }

                    $updates = [];

                    $timezone = $settings['timezone'] ?? null;
                    if (is_string($timezone) && $timezone !== '' && $tenant->timezone === 'Africa/Addis_Ababa' && $timezone !== $tenant->timezone) {
                        $updates['timezone'] = mb_substr($timezone, 0, 64);
                    }

                    $locale = $settings['locale'] ?? null;
                    if (is_string($locale) && $locale !== '' && $tenant->default_locale === 'en' && $locale !== $tenant->default_locale) {
                        $updates['default_locale'] = mb_substr($locale, 0, 5);
                    }

                    if ($updates !== []) {
                        DB::table('tenants')->where('id', $tenant->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data backfill only — the previous values are still in the settings
        // JSON, which this migration never touches, so there is nothing to undo.
    }
};
