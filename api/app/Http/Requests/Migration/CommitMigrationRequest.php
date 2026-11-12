<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration;

use App\Support\EmployeeImportRow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a staged list, and what the whole list is brought in with.
 *
 * `status` is what the list's new people arrive as. People who already work
 * here are not newly hired: brought in as `confirmed` they can resign, be
 * suspended or take leave from the first day, which someone `hired` cannot.
 * A line that names its own status keeps it. Nothing sent leaves them
 * `hired`, as before.
 *
 * It is read when the list begins to be added and kept with it. A request
 * that carries on a list already under way cannot change it.
 */
class CommitMigrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(EmployeeImportRow::STATUSES)],
        ];
    }

    /**
     * What the reviewer chose for the list, or null when they chose nothing.
     *
     * @return array{status: string}|null
     */
    public function options(): ?array
    {
        $status = $this->validated('status');

        return is_string($status) ? ['status' => $status] : null;
    }
}
