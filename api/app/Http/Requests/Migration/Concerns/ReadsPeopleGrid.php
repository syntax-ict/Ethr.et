<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration\Concerns;

use App\Models\ImportMapping;
use App\Services\CurrentTenant;
use App\Support\EmployeeImportGrid;
use App\Support\EmployeeImportRow;
use App\Support\PeopleRead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A request that carries a list of people as cells (`grid`) and the calendar
 * its dates are written in. The list is read once, however often it is asked for.
 *
 * It may also say how the list names its columns: `source`, a saved source
 * of the organisation's (ImportMapping), and `mapping`, column names matched
 * in this request, which are read over the saved ones. With either, the
 * first line is column names because the reader said so.
 *
 * @mixin FormRequest
 */
trait ReadsPeopleGrid
{
    private ?PeopleRead $read = null;

    private ImportMapping|false|null $importSource = null;

    /**
     * @return array<string, list<mixed>>
     */
    protected function gridRules(string $presence): array
    {
        return [
            'calendar' => ['sometimes', 'string', 'in:gregorian,ethiopian'],
            'grid' => [$presence, 'array', 'min:1', 'max:5000'],
            'grid.*' => ['array', 'max:60'],
            'grid.*.*' => ['nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'size:26'],
            ...EmployeeImportRow::mappingRules('sometimes'),
        ];
    }

    /**
     * The saved source the request names, or null when it names none. It is
     * looked up as the organisation's own; one that is not there is refused
     * by checkSource() with the words an unknown id gets.
     */
    public function importSource(): ?ImportMapping
    {
        if ($this->importSource === null) {
            $publicId = $this->input('source');
            $this->importSource = is_string($publicId) && $publicId !== ''
                ? (ImportMapping::query()->where('public_id', $publicId)->first() ?? false)
                : false;
        }

        return $this->importSource === false ? null : $this->importSource;
    }

    protected function checkSource(Validator $validator): void
    {
        $publicId = $this->input('source');

        if (is_string($publicId) && $publicId !== '' && $this->importSource() === null) {
            $validator->errors()->add('source', __('validation.exists', ['attribute' => 'source']));
        }
    }

    /**
     * What the reader said each column name is: the saved source's columns,
     * and over them the ones matched in this request. Null when they said
     * nothing, and the list is read as ETHR reads any list.
     *
     * @return array<string, string|null>|null
     */
    public function columnMapping(): ?array
    {
        $source = $this->importSource();
        $matched = $this->input('mapping');

        if ($source === null && ! is_array($matched)) {
            return null;
        }

        return [...($source?->map() ?? []), ...ImportMapping::mapOf(is_array($matched) ? $matched : [])];
    }

    /** What an id from the other system is kept against: the saved source's own name, or the default. */
    public function sourceName(): string
    {
        $source = $this->importSource();

        return $source === null ? EmployeeImportRow::DEFAULT_SOURCE : $source->slug;
    }

    /** The list as EmployeeImportGrid reads it, or null when the request brings no grid. */
    public function people(): ?PeopleRead
    {
        $grid = $this->input('grid');
        $tenantId = app(CurrentTenant::class)->id();

        if (! is_array($grid) || $tenantId === null) {
            return null;
        }

        return $this->read ??= (new EmployeeImportGrid($tenantId))->read(
            $grid,
            $this->input('calendar') === 'ethiopian' ? 'ethiopian' : 'gregorian',
            $this->columnMapping(),
            $this->sourceName(),
        );
    }
}
