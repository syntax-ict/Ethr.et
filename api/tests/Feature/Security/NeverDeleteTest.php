<?php

declare(strict_types=1);

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Traits\NeverDelete;
use Illuminate\Support\Facades\DB;

/*
 * The NeverDelete trait, at the Eloquent layer.
 *
 * AuditLogImmutabilityTest already proves a row cannot be deleted -- but it does
 * so with DB::table('audit_log')->delete(), which tests the database trigger and
 * never touches the model. That is why this trait measured 0.0% coverage on
 * 2026-09-23 (audit/BASELINE.md 12g) while the behaviour it guards was, in one
 * sense, already covered: the control exists at two layers and only one was
 * exercised.
 *
 * The two layers fail differently and both matter. The trigger stops any client
 * of the database, including a restored dump replayed by hand. The trait stops
 * application code, and it is the only one of the two that survives a restore
 * onto a host where the trigger did not come back -- which BACKUP-RESTORE.md
 * records as a real possibility for a Plesk panel export.
 */

// Exactly the rows docs/CLAUDE.md's soft-delete policy marks "Never delete".
$neverDeletable = [
    'attendance records' => AttendanceRecord::class,
    'payroll entries' => PayrollEntry::class,
    'payroll runs' => PayrollRun::class,
    'audit logs' => AuditLog::class,
];

test('every entity the soft-delete policy marks "Never delete" uses the trait', function () use ($neverDeletable) {
    // The policy table is prose; this is the part of it that is enforced.
    // Removing the trait from one of these models fails here rather than in
    // production, which is the gap docs/CLAUDE.md records as "a convention, not
    // a control".
    //
    // Collected into a list rather than asserted per model, because Pest's
    // toContain() is variadic over NEEDLES -- a second argument is another
    // thing to search for, not a failure message. Passing one there is what
    // made the first version of this test fail against models that do carry
    // the trait. An empty-array assertion names the offenders on failure
    // without needing a message at all.
    $missing = [];

    foreach ($neverDeletable as $label => $model) {
        if (! in_array(NeverDelete::class, class_uses_recursive($model), true)) {
            $missing[] = "{$label} ({$model})";
        }
    }

    expect($missing)->toBe([]);
});

test('delete() throws rather than deleting', function (string $model) {
    expect(fn () => (new $model)->delete())
        ->toThrow(LogicException::class, 'records cannot be deleted');
})->with(array_values($neverDeletable));

test('forceDelete() throws too, so SoftDeletes cannot be used to get round it', function (string $model) {
    expect(fn () => (new $model)->forceDelete())
        ->toThrow(LogicException::class, 'records cannot be deleted');
})->with(array_values($neverDeletable));

test('the row is still there after a refused delete', function () {
    createTenant();
    $log = AuditLog::record('never-delete.probe');

    expect(fn () => $log->delete())->toThrow(LogicException::class);

    // Read round the model to prove the row survived rather than that the model
    // merely refused -- this is the assertion the throw test cannot make.
    expect(DB::table('audit_log')->where('action', 'never-delete.probe')->count())->toBe(1);
});

test('the exception names the model, so a caller knows which record refused', function () {
    expect(fn () => (new PayrollRun)->delete())
        ->toThrow(LogicException::class, PayrollRun::class);
});
