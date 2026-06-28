<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $accountNumber = $this->account_number;
        $masked = str_repeat('*', max(0, strlen($accountNumber) - 4)) . substr($accountNumber, -4);

        return [
            'id' => $this->id,
            'bank_name' => $this->bank_name,
            'branch_name' => $this->branch_name,
            'account_number_masked' => $masked,
            'is_primary' => $this->is_primary,
        ];
    }
}
