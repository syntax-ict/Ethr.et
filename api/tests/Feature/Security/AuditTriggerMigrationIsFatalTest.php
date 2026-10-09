<?php

declare(strict_types=1);

/**
 * The audit-log trigger migration must abort the deployment when it cannot apply.
 *
 * WHY THIS IS A TEST AND NOT A COMMENT. `CREATE TRIGGER` on the Bronze target is
 * **NOT VERIFIED** — probe `DB4` has never been placed on the host — and there is
 * standing pressure to make `migrate` survive a denial so a deployment can proceed.
 * `docs/deployment/SHARED-HOSTING-CONTRACT.md` names the collision explicitly and
 * pre-registers it as the owner's (**Q8**): the hard rule forbids *requiring* a
 * provider concession, and this migration requires one.
 *
 * That tension is legitimate and this test does not resolve it. What it prevents is
 * the tension being resolved **quietly, by an agent or a hurried deploy**, in the one
 * direction that looks like progress and is not.
 *
 * WHY A SOFTENED MIGRATION IS WORSE THAN A FAILED ONE. `AuditLog`'s `update()` and
 * `delete()` overrides are plain instance methods, so **every mass-operation path
 * walks straight past them** — `DB::table('audit_log')->update()`,
 * `AuditLog::where(...)->delete()`, raw SQL. The database trigger is the only
 * control that survives those. `AuditLogImmutabilityTest` asserts exactly that by
 * driving the raw query builder, so a deployment whose triggers silently failed to
 * install is in a state **the test suite contradicts while CI stays green**.
 *
 * `docs/AUDIT_LOG_INTEGRITY_DECISION.md` records that an earlier revision downgraded
 * the failure to "log and continue" and that it was reverted for this reason.
 * Convention #5 — immutable audit log — is Non-Negotiable.
 *
 * WHAT THIS ASSERTS. Static properties of the migration source: that the MySQL branch
 * rethrows, and that no environment flag can turn the requirement off. It does not
 * attempt to revoke a privilege and observe the abort — that needs DDL against a
 * server this suite does not have, and `BASELINE.md` §13b covers the behaviour.
 *
 * No database, no network.
 */
function ethrTriggerMigrationSource(): string
{
    $matches = glob(base_path('database/migrations/*restrict_audit_log_to_insert_only.php'));

    expect($matches)->toHaveCount(1, 'the audit-log trigger migration is missing or was renamed');

    return (string) file_get_contents($matches[0]);
}

it('rethrows when the trigger cannot be created', function () {
    $source = ethrTriggerMigrationSource();

    // The catch exists to ADD a diagnosis, not to absorb the failure. A bare
    // SQLSTATE 42000 tells an operator nothing about which privilege is missing.
    expect($source)->toContain('catch (Throwable $e)');
    expect($source)->toContain('throw $e;');

    // The throw must be reachable from the catch: a `catch` that logs and falls
    // through is precisely the reverted "log and continue" behaviour.
    $catchAt = strpos($source, 'catch (Throwable $e)');
    $throwAt = strpos($source, 'throw $e;');

    expect($catchAt)->not->toBeFalse();
    expect($throwAt)->not->toBeFalse();
    expect($throwAt)->toBeGreaterThan(
        $catchAt,
        'the rethrow no longer follows the catch that diagnoses the failure'
    );
});

it('offers no way to opt out of the requirement', function () {
    $source = ethrTriggerMigrationSource();

    // Every shape this has been proposed in, or could plausibly be added in. The
    // flag named in the contract as the thing that would have to be BUILT to accept
    // the downgrade is AUDIT_LOG_REQUIRE_DB_IMMUTABILITY — and
    // AUDIT_LOG_INTEGRITY_DECISION.md deliberately does not build it.
    foreach ([
        'AUDIT_LOG_REQUIRE_DB_IMMUTABILITY',
        'SKIP_AUDIT_TRIGGER',
        'ETHR_SKIP_TRIGGERS',
        'ALLOW_MISSING_TRIGGER',
    ] as $escapeHatch) {
        expect(str_contains($source, $escapeHatch))->toBeFalse(
            "the migration reads {$escapeHatch}. A deployment must not be able to opt out of "
            .'the audit-log trigger: the model-level overrides are instance methods that every '
            .'mass-update path bypasses, so without the trigger convention #5 is unenforced '
            .'while AuditLogImmutabilityTest still passes. If the owner has accepted that '
            .'downgrade (question Q8), it needs a decision record and this test updated '
            .'deliberately — not a flag added quietly.'
        );
    }

    // And no env()/getenv() read at all inside the migration, which is the general
    // form of the same escape hatch under a name nobody listed above.
    expect($source)->not->toMatch(
        '/\b(env|getenv)\s*\(/',
        'the migration reads the environment. Whether the audit log is immutable must not '
        .'depend on a deployment-time variable.'
    );
});

it('still refuses to run on an unsupported driver rather than skipping', function () {
    $source = ethrTriggerMigrationSource();

    // SQLite gets real triggers too (the suite runs on it, and AuditLogImmutabilityTest
    // depends on them being there). What must not exist is a silent `return` for an
    // unrecognised driver, which would make the guard absent exactly where nobody
    // looked.
    expect($source)->toContain("'sqlite'");

    // The MySQL/MariaDB branch is the production one.
    expect($source)->toMatch('/mariadb|mysql/i');
});
