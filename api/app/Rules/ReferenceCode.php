<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\ReferenceItem;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A code that names an entry of one of the shared pick lists (regions,
 * sub-cities, towns, banks).
 *
 * The code must exist in the list. It need not still be active: a record
 * saved before an entry was retired keeps its code, and refusing it would
 * block a form over a value nobody just typed. A new choice can only come
 * from the active list the form shows.
 *
 * With `$parentField`, an entry that names a parent must name the one the
 * request sends in that field, so a town cannot sit under another region.
 *
 * The message is Laravel's generic `validation.exists`, the same for an
 * unknown code and for a mismatched one.
 */
final class ReferenceCode implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  list<string>  $kinds  which lists the code may come from
     * @param  string|null  $parentField  the request field holding the parent's code
     */
    public function __construct(
        private readonly array $kinds,
        private readonly ?string $parentField = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('validation.exists')->translate();

            return;
        }

        $item = ReferenceItem::query()
            ->whereIn('kind', $this->kinds)
            ->where('code', $value)
            ->first();

        if ($item === null) {
            $fail('validation.exists')->translate();

            return;
        }

        $parent = $this->parentField === null ? null : ($this->data[$this->parentField] ?? null);

        if (is_string($parent) && $parent !== '' && $item->parent_code !== null && $item->parent_code !== $parent) {
            $fail('validation.exists')->translate();
        }
    }
}
