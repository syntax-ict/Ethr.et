<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether `audit_log` is still append-only — checked by doing, not by
 * reading the trigger list.
 *
 * A restore can bring the triggers back by name and still leave them broken
 * (the DEFINER problem, BACKUP-RESTORE.md), and convention #5 says the log is
 * append-only; right after a restore is exactly when that quietly stops being
 * true. So this writes one row — which doubles as the record that the check
 * ran — and then tries to change it: the insert must succeed and the update
 * must be refused. Used by `ethr:backup:rehearse`, `ethr:restore` and the
 * maintenance endpoint's `restore` task.
 */
final class AuditLogIntegrity
{
    /**
     * @param  array<string, mixed>  $payload  recorded on the row this check writes
     * @return list<string> what is wrong; empty when the log is intact
     */
    public function verifyAppendOnly(string $action, array $payload = []): array
    {
        if (! Schema::hasTable('audit_log')) {
            return ['audit_log did not come back at all.'];
        }

        try {
            $id = DB::table('audit_log')->insertGetId([
                'action' => $action,
                'payload' => json_encode($payload),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The DEFINER failure's actual signature. AuditLog::record() runs
            // inside the write transaction of nearly every mutating endpoint, so
            // in production this reads as "everything returns 500".
            return ['audit_log INSERT failed: '.$e->getMessage()
                .' — if this mentions a definer, the dump carried one.'];
        }

        try {
            DB::table('audit_log')->where('id', $id)->update(['action' => 'tampered']);
        } catch (Throwable) {
            return [];
        }

        return ['audit_log accepted an UPDATE — the append-only triggers came back by name '
            .'but are not enforcing. The log is no longer evidence.'];
    }
}
