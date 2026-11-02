<?php

declare(strict_types=1);

use App\Services\Leave\LeaveYear;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The leave year became an organisation setting on 2026-11-02 (#521), with
 * Gregorian for any organisation that had not chosen. The Ethiopian year is
 * now the default for everyone, so this writes the setting into every
 * organisation that has none: the Ethiopian year, or the Gregorian year it
 * already runs on when leave is recorded under those year numbers
 * (`LeaveYear::forExisting`). Writing it explicitly is what keeps the second
 * group where it is once the default changes.
 *
 * A setting an organisation already chose is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->select('id', 'settings')
            ->orderBy('id')
            ->chunk(200, function ($tenants) {
                foreach ($tenants as $tenant) {
                    $settings = json_decode((string) $tenant->settings, true);
                    $settings = is_array($settings) ? $settings : [];

                    if (in_array($settings['leave_year'] ?? null, LeaveYear::CHOICES, true)) {
                        continue;
                    }

                    $settings['leave_year'] = LeaveYear::forExisting((int) $tenant->id);

                    DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings)]);
                }
            });
    }

    public function down(): void
    {
        // Data backfill only. Removing the setting would move every
        // organisation onto the new default, not back to where it was.
    }
};
