<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ReferenceItem;
use App\Support\Reference\ReferenceCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The pick lists the forms offer: regions, the sub-cities of Addis Ababa, the
 * main towns and the banks. Public, like the plan and organisation-type
 * catalogues: it names places and banks, not anyone's data.
 *
 * One request returns every list, because the forms that need one often need
 * another, and the whole payload is small. `version` is a hash of the content,
 * so a client can tell whether what it holds is current. Rows that have left
 * the data files are not listed; a record that stored one of their codes keeps
 * its own text.
 */
class LookupController extends Controller
{
    /**
     * @response array{version: string, items: array{region: list<array{code: string, parent_code: string|null, name: string, name_am: string, name_om: string|null, meta: array<string, mixed>}>, sub_city: list<array{code: string, parent_code: string|null, name: string, name_am: string, name_om: string|null, meta: array<string, mixed>}>, town: list<array{code: string, parent_code: string|null, name: string, name_am: string, name_om: string|null, meta: array<string, mixed>}>, bank: list<array{code: string, parent_code: string|null, name: string, name_am: string, name_om: string|null, meta: array<string, mixed>}>}}
     */
    public function index(): JsonResponse
    {
        $payload = Cache::remember(ReferenceCatalogue::CACHE_KEY, 3600, function (): array {
            $items = [];

            foreach (ReferenceCatalogue::KINDS as $kind) {
                $items[$kind] = ReferenceItem::query()
                    ->ofKind($kind)
                    ->active()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (ReferenceItem $item): array => [
                        'code' => $item->code,
                        'parent_code' => $item->parent_code,
                        'name' => $item->name,
                        'name_am' => $item->name_am,
                        'name_om' => $item->name_om,
                        'meta' => (object) ($item->meta ?? []),
                    ])
                    ->values()
                    ->all();
            }

            return [
                'version' => substr(sha1(json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 12),
                'items' => $items,
            ];
        });

        return response()->json($payload);
    }
}
