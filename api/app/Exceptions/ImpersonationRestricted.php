<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\ImpersonationToken;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * An operator in a support session asked for something only the organisation
 * itself may do: release its pay, build its bank file, or write where its
 * people are paid.
 *
 * Thrown from the middleware that names those actions and from the domain code
 * beneath them, so a route added later is refused as well. bootstrap/app.php
 * renders it as a 403 problem+json and not as an error to report. The refusal
 * is recorded when it is rendered, after any transaction it was thrown from
 * has unwound, so it survives the rollback it caused.
 *
 * The record names the action and the fields that were refused, never a value.
 */
class ImpersonationRestricted extends RuntimeException
{
    /** @param  list<string>  $fields  names of the request fields refused, never their values */
    public function __construct(public readonly string $action, public readonly array $fields = [])
    {
        parent::__construct(__('auth.impersonation_restricted'));
    }

    /** @param  list<string>  $fields */
    public static function unlessOrganisation(string $action, array $fields = []): void
    {
        if (ImpersonationToken::sessionActive()) {
            throw new self($action, $fields);
        }
    }

    public function render(Request $request): ?JsonResponse
    {
        $this->record();

        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'type' => 'https://ethr.et/errors/impersonation-restricted',
            'title' => 'Action Restricted',
            'status' => 403,
            'detail' => $this->getMessage(),
        ], 403)->header('Content-Type', 'application/problem+json');
    }

    private function record(): void
    {
        try {
            $user = auth()->user();
            $payload = ['action' => $this->action];
            if ($this->fields !== []) {
                $payload['fields'] = $this->fields;
            }

            $auditable = app(CurrentTenant::class)->id() === null && $user instanceof User ? $user : null;

            AuditLog::record('admin.tenant.impersonation_refused', $auditable, $payload);
        } catch (Throwable $failure) {
            report($failure);
        }
    }
}
