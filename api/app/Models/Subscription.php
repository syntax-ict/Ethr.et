<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Traits\BelongsToTenant;
use App\Traits\HasAuditLog;
use App\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's subscription to a plan.
 *
 * No `@property` block, unlike PersonnelAction and the other models that carry
 * one: those tables have a JSON column Larastan cannot introspect, so their
 * types have to be declared. This table is plain, Larastan reads the migrations,
 * and `price_cents` is declared `nullable()` there — which is what makes the
 * null branch in `effectivePriceCents()` analysable rather than dead code. A
 * partial block here would override two properties and leave the enum cast on
 * `status` to inference anyway, for no gain.
 */
class Subscription extends Model
{
    use BelongsToTenant, HasAuditLog, HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'plan_id',
        'price_cents',
        'status',
        'seats',
        'current_period_start',
        'current_period_end',
        'paused_at',
        'cancelled_at',
    ];

    protected $hidden = [
        'id',
        'tenant_id',
        'plan_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'price_cents' => 'integer',
            'seats' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * What this subscriber actually pays per period, in cents.
     *
     * The captured price when there is one, which is every row the
     * `add_price_cents_to_subscriptions` backfill touched and every row written
     * since. Editing the plan's catalog price does not move this number, which
     * is the whole point: the catalog is a list price for new customers, not a
     * live instruction to re-bill everyone already on that plan.
     *
     * The fallback to the plan is for rows that predate capture and somehow
     * escaped the backfill — a raw insert, a restored dump. It reproduces the
     * old behaviour rather than billing zero, because an invoice that is wrongly
     * zero is the one nobody complains about until the year is over.
     */
    public function effectivePriceCents(): int
    {
        // Read through getAttributes() rather than `$this->price_cents`, and
        // annotate the relation, because Larastan infers model property types
        // from the migrations and gets both of these wrong in ways that are
        // invisible here and fatal in CI.
        //
        // It resolves cast properties to their raw backing type — the same
        // limitation phpstan.neon documents for RetirementCase and
        // DisciplinaryCase, and that phpstan-baseline.neon already records
        // against this very file ("Cannot access property $value on string",
        // for `$subscription->status?->value`). A null check against a property
        // it has decided cannot be null is then reported as dead code, and the
        // gate fails on correct code.
        //
        // getAttributes() returns the uncast attribute as `mixed`, which is
        // both what this method actually wants — the stored figure, not a cast
        // view of it — and a type no always-true/false analysis can fire on.
        $captured = $this->getAttributes()['price_cents'] ?? null;

        if ($captured !== null) {
            return (int) $captured;
        }

        /** @var Plan|null $plan */
        $plan = $this->getRelationValue('plan');

        return $plan?->price_cents ?? 0;
    }
}
