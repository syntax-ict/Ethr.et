<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NationalityStatus;
use App\Services\Payroll\PensionCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Sets `nationality_status` for employees who had only the free-text
 * nationality (migration 2026_10_14_000006).
 *
 * Blank, or a spelling PensionCalculator::isEthiopian() knows → `ethiopian`.
 * Anything else → `foreign`, and the employee is flagged
 * (`nationality_needs_review`) for a person to confirm. No further guessing:
 * "Ethiopean" is flagged, not corrected — the point of the change is that a
 * person, not a spelling, decides whether someone pays pension.
 *
 * Across every tenant and soft-deleted rows too, through the query builder,
 * so no tenant scope applies and none is needed: each row is mapped from its
 * own value.
 */
final class NationalityBackfill
{
    /**
     * @return array{ethiopian: int, flagged: int, flagged_ids: list<int>}
     */
    public static function run(): array
    {
        $result = ['ethiopian' => 0, 'flagged' => 0, 'flagged_ids' => []];

        DB::table('employees')
            ->select(['id', 'nationality'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$result): void {
                $ethiopian = [];
                $foreign = [];
                foreach ($rows as $row) {
                    if (PensionCalculator::isEthiopian($row->nationality)) {
                        $ethiopian[] = $row->id;
                    } else {
                        $foreign[] = $row->id;
                    }
                }

                if ($ethiopian !== []) {
                    DB::table('employees')->whereIn('id', $ethiopian)->update([
                        'nationality_status' => NationalityStatus::ETHIOPIAN->value,
                        'nationality_needs_review' => false,
                    ]);
                }
                if ($foreign !== []) {
                    DB::table('employees')->whereIn('id', $foreign)->update([
                        'nationality_status' => NationalityStatus::FOREIGN->value,
                        'nationality_needs_review' => true,
                    ]);
                }

                $result['ethiopian'] += count($ethiopian);
                $result['flagged'] += count($foreign);
                array_push($result['flagged_ids'], ...$foreign);
            });

        return $result;
    }
}
