<?php

declare(strict_types=1);

use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;

/**
 * The committed pricing snapshot must still describe the seeded catalog.
 *
 * `src/src/lib/marketing/plans-snapshot.json` is what `/pricing` renders into
 * its prerendered HTML — the figures a crawler and a link preview see, and the
 * ones a visitor sees on first paint before the live fetch resolves. It is a
 * cache with a manual refresh (`node scripts/fetch-plans-snapshot.mjs`), and
 * nothing made anyone perform it.
 *
 * So a developer could change a price in `PlanSeeder` and the public page would
 * keep advertising the old one. That is precisely the defect this branch
 * exists to remove — a number on the marketing site that disagrees with what
 * the product will honour — reintroduced one layer down, and invisible because
 * every gate would stay green.
 *
 * WHAT THIS CANNOT CATCH, stated so nobody reads more into a green run: once a
 * super admin edits a plan at `/admin/plans`, the snapshot is stale by design
 * until someone regenerates and redeploys. No test in CI can see a production
 * database. This pins the half that is checkable — that the committed file and
 * the seeded catalog agree — which is the half a developer can get wrong.
 *
 * `public_id` is excluded deliberately: it is a ULID generated per database, so
 * it differs between the machine that generated the snapshot and this one.
 * Everything else here is deterministic and comes from the seeder.
 */

/**
 * Where the committed snapshot lives, or null when the frontend tree is not
 * reachable from this run.
 *
 * `scripts/pest-isolated.sh` copies `api/` into `/tmp/pest-run` inside the
 * container — the documented workaround for PHP's incomplete directory scan
 * over a Docker Desktop Windows bind mount — and there is no `src/` beside it.
 * Failing there would be a false alarm on the one workflow that exists to make
 * the suite runnable at all.
 *
 * The check is deliberately on the frontend *directory*, not on the snapshot
 * file: an absent tree means "cannot see it from here", while an absent file
 * inside a present tree means the snapshot is gone, which is a real defect and
 * must fail.
 */
function pricingSnapshotPath(): ?string
{
    $frontend = base_path('../src/src/lib/marketing');

    return is_dir($frontend) ? $frontend.'/plans-snapshot.json' : null;
}
it('describes the same catalog PlanSeeder writes', function () {
    $path = pricingSnapshotPath();

    if ($path === null) {
        $this->markTestSkipped('Frontend tree not reachable — running from an isolated copy of api/.');
    }

    $this->seed(PlanSeeder::class);

    expect(file_exists($path))->toBeTrue(
        "plans-snapshot.json is missing at {$path} — /pricing would prerender with no prices."
    );

    /** @var array{data: list<array<string, mixed>>} $snapshot */
    $snapshot = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    $compared = [
        'name', 'slug', 'description', 'description_am',
        'price_cents', 'currency', 'billing_interval',
        'max_employees', 'max_branches', 'max_devices',
        'features', 'marketing_features', 'marketing_features_am',
        'is_popular', 'sort_order',
    ];

    $fromDatabase = Plan::where('is_active', true)
        ->where('is_public', true)
        ->orderBy('sort_order')
        ->get()
        ->map(fn ($plan) => collect($plan->only($compared))->sortKeys()->all())
        ->all();

    $fromSnapshot = collect($snapshot['data'])
        ->map(fn (array $row) => collect($row)->only($compared)->sortKeys()->all())
        ->all();

    expect($fromSnapshot)->toEqual(
        $fromDatabase,
        'plans-snapshot.json no longer matches PlanSeeder. The prerendered '
        .'pricing page would advertise figures the catalog does not hold. '
        .'Regenerate it: node scripts/fetch-plans-snapshot.mjs --api <url>'
    );
});

it('carries every field the public endpoint publishes', function () {
    $path = pricingSnapshotPath();

    if ($path === null) {
        $this->markTestSkipped('Frontend tree not reachable — running from an isolated copy of api/.');
    }

    /** @var array{data: list<array<string, mixed>>} $snapshot */
    $snapshot = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    // The generator projects a fixed field list. When the catalog gained
    // description, currency, marketing_features and is_popular, the first
    // regenerated snapshot silently carried none of them — the prerendered page
    // would have shown bare cards while the client fetch filled them in a
    // moment later. The script now refuses an unknown field; this is the other
    // direction, a field the script knows and the file lacks.
    // A bare model, not a factory: only the key set matters here, and the
    // factory needs fakerphp, which is a dev dependency this assertion has no
    // reason to depend on.
    $published = array_keys(
        (new PlanResource(new Plan))->toArray(request())
    );

    foreach ($snapshot['data'] as $row) {
        expect(array_keys($row))->toEqualCanonicalizing(
            $published,
            'plans-snapshot.json is missing a field PlanResource publishes, or '
            .'carries one it does not. Regenerate with '
            .'scripts/fetch-plans-snapshot.mjs.'
        );
    }
});
