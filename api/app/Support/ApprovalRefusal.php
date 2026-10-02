<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Why a decision on a pending request was refused after authorisation passed.
 *
 * The single-item endpoints render it as a problem+json response; the batch
 * approval endpoint renders the same refusal as one failed item. Both read it
 * from the same decision service, so the two paths cannot disagree about when
 * a decision is allowed.
 */
final readonly class ApprovalRefusal
{
    public function __construct(
        public int $status,
        public string $type,
        public string $title,
        public string $detail,
    ) {}

    public static function notPending(string $detail): self
    {
        return new self(422, 'https://ethr.et/errors/invalid-state', 'Invalid State', $detail);
    }

    public static function selfApproval(string $detail): self
    {
        return new self(403, 'https://ethr.et/errors/self-approval', 'Self-Approval Forbidden', $detail);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
        ], $this->status)->header('Content-Type', 'application/problem+json');
    }
}
