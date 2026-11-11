<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration\Concerns;

use App\Services\CurrentTenant;
use App\Support\EmployeeImportGrid;
use App\Support\PeopleRead;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request that carries a list of people as cells (`grid`) and the calendar
 * its dates are written in. The list is read once, however often it is asked for.
 *
 * @mixin FormRequest
 */
trait ReadsPeopleGrid
{
    private ?PeopleRead $read = null;

    /**
     * @return array<string, list<string>>
     */
    protected function gridRules(string $presence): array
    {
        return [
            'calendar' => ['sometimes', 'string', 'in:gregorian,ethiopian'],
            'grid' => [$presence, 'array', 'min:1', 'max:5000'],
            'grid.*' => ['array', 'max:60'],
            'grid.*.*' => ['nullable', 'string', 'max:255'],
        ];
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
        );
    }
}
