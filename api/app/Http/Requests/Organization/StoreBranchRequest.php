<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Http\Requests\Organization\Concerns\BranchRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
{
    use BranchRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->branchRules('required');
    }
}
