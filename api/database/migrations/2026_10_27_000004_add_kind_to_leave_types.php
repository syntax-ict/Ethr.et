<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A leave type says what kind of time away it is.
 *
 *  - `leave`: time off, as every type has been until now.
 *  - `duty`: away on the organisation's business, a trip or an assignment.
 *  - `excused`: an absence the organisation excuses, paid.
 *
 * An approved request of any kind explains the day everywhere attendance asks
 * (ExpectedDays already treats approved leave so), which is how a business
 * trip or an excused day stops being reconciled as an unexplained absence.
 * Duty and excused days are not counted as leave taken and draw on no balance.
 *
 * Every organisation gets the two catalog types, `official_duty` and
 * `excused`, unless it already has a type with that code, and the catalog's
 * `unpaid` type when it has no unpaid type at all: that is how an absence is
 * recorded as unpaid. Nothing an organisation created is changed.
 */
return new class extends Migration
{
    /** @var array<string, array<string, mixed>> */
    private const TYPES = [
        'official_duty' => [
            'name' => 'Official Duty / Business Trip',
            'name_am' => 'የሥራ ጉዞ / ተልዕኮ',
            'kind' => 'duty',
            'sort_order' => 120,
        ],
        'excused' => [
            'name' => 'Excused Absence',
            'name_am' => 'የተፈቀደ መቅረት',
            'kind' => 'excused',
            'sort_order' => 130,
        ],
    ];

    /** The catalog's own (LeaveTypeCatalog `unpaid`). */
    private const UNPAID = [
        'name' => 'Unpaid Leave',
        'name_am' => 'ያለክፍያ ፈቃድ',
        'kind' => 'leave',
        'is_paid' => false,
        'min_notice_days' => 14,
        'sort_order' => 110,
    ];

    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->string('kind', 20)->default('leave')->after('code');
        });

        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $types = self::TYPES;
            $hasUnpaid = DB::table('leave_types')
                ->where('tenant_id', $tenantId)
                ->where('is_paid', false)
                ->exists();
            if (! $hasUnpaid) {
                $types['unpaid'] = self::UNPAID;
            }

            foreach ($types as $code => $type) {
                $exists = DB::table('leave_types')
                    ->where('tenant_id', $tenantId)
                    ->where('code', $code)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('leave_types')->insert([
                    'is_paid' => true,
                    'min_notice_days' => 0,
                    ...$type,
                    'public_id' => (string) Str::ulid(),
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'default_days' => 0,
                    'accrual_type' => 'annual',
                    'carry_forward' => false,
                    'requires_approval' => true,
                    'requires_attachment' => false,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * The catalog types are left in place: requests may already name them,
     * and a type is never deleted (soft-delete policy).
     */
    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
