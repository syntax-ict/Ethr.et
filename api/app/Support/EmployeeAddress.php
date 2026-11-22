<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;
use App\Models\ReferenceItem;
use App\Rules\ReferenceCode;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * An employee's address: the one place its rules and its code-beside-text
 * behaviour live, so an HR create, an HR edit, a reviewed import and the
 * employee's own profile cannot disagree.
 *
 * The rule is the pick lists': a code sits beside the text, never instead of
 * it. A name changed without a code drops the old code, so a code never stands
 * against other text. A region that moves takes its sub-city and town codes
 * with it. A code the lists have since retired is still accepted.
 */
final class EmployeeAddress
{
    /** The pairs a pick list fills: [code column, text column]. */
    private const PAIRS = [
        ['address_region_code', 'address_region_text'],
        ['address_sub_city_code', 'address_zone_or_sub_city_text'],
        ['address_town_code', 'address_town_text'],
    ];

    /** @var list<string> */
    public const COLUMNS = [
        'address_region_code',
        'address_region_text',
        'address_sub_city_code',
        'address_zone_or_sub_city_text',
        'address_town_code',
        'address_town_text',
        'address_woreda',
        'address_kebele',
        'address_house_number',
    ];

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'address_region_code' => ['nullable', 'string', 'max:60', new ReferenceCode(['region'])],
            'address_region_text' => ['nullable', 'required_with:address_region_code', 'string', 'max:100'],
            'address_sub_city_code' => ['nullable', 'string', 'max:60', new ReferenceCode(['sub_city'], 'address_region_code')],
            'address_zone_or_sub_city_text' => ['nullable', 'required_with:address_sub_city_code', 'string', 'max:100'],
            'address_town_code' => ['nullable', 'string', 'max:60', new ReferenceCode(['town'], 'address_region_code')],
            'address_town_text' => ['nullable', 'required_with:address_town_code', 'string', 'max:100'],
            'address_woreda' => ['nullable', 'string', 'max:50'],
            'address_kebele' => ['nullable', 'string', 'max:50'],
            'address_house_number' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * What is saved for the address fields in `$attributes`, with the codes that
     * no longer stand dropped to an explicit null.
     *
     * A code goes only when its text key is sent, differs from the stored text
     * and no code key came with it; the same text with no code keeps the code.
     * A new region sent without a sub-city or town clears the ones the old
     * region had. For a record being created there is nothing stored to drop.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalize(array $attributes, ?Employee $stored): array
    {
        if ($stored === null) {
            return $attributes;
        }

        foreach (self::PAIRS as [$codeKey, $textKey]) {
            if (
                array_key_exists($textKey, $attributes)
                && ! array_key_exists($codeKey, $attributes)
                && $stored->{$codeKey} !== null
                && (string) $attributes[$textKey] !== (string) $stored->{$textKey}
            ) {
                $attributes[$codeKey] = null;
            }
        }

        if (
            array_key_exists('address_region_code', $attributes)
            && $attributes['address_region_code'] !== $stored->address_region_code
        ) {
            foreach (['address_sub_city_code', 'address_town_code'] as $child) {
                if (! array_key_exists($child, $attributes) && $stored->{$child} !== null) {
                    $attributes[$child] = null;
                }
            }
        }

        return $attributes;
    }

    /**
     * The checks a rule cannot make alone, because they need the stored region.
     *
     * A sub-city or town code sent on its own is judged against the region the
     * employee already has, so a PUT of one code cannot slide a town under
     * another region. With no region at all, sent or stored, there is nothing to
     * place it under, and the region is asked for. The parent mismatch gets
     * Laravel's generic `validation.exists`, as everywhere else.
     */
    public static function check(Validator $validator, ?Employee $stored): void
    {
        $validator->after(function (Validator $validator) use ($stored): void {
            $sent = Arr::only($validator->getData(), self::COLUMNS);
            $effective = self::normalize($sent, $stored);
            $region = array_key_exists('address_region_code', $effective)
                ? $effective['address_region_code']
                : $stored?->address_region_code;

            foreach (['address_sub_city_code', 'address_town_code'] as $field) {
                $code = $sent[$field] ?? null;

                if (! is_string($code) || $code === '' || $validator->errors()->has($field)) {
                    continue;
                }

                if (! is_string($region) || $region === '') {
                    $validator->errors()->add('address_region_code', __('validation.required_with', [
                        'attribute' => 'address region code',
                        'values' => str_replace('_', ' ', $field),
                    ]));

                    continue;
                }

                $parent = ReferenceItem::query()
                    ->where('kind', $field === 'address_town_code' ? 'town' : 'sub_city')
                    ->where('code', $code)
                    ->value('parent_code');

                if ($parent !== null && $parent !== $region) {
                    $validator->errors()->add($field, __('validation.exists', [
                        'attribute' => str_replace('_', ' ', $field),
                    ]));
                }
            }
        });
    }
}
