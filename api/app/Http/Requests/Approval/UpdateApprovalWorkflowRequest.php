<?php

declare(strict_types=1);

namespace App\Http\Requests\Approval;

/**
 * Updating replaces the whole workflow, steps included: a chain is short and
 * read as a whole, so a partial edit that left the steps half-renumbered
 * would be worse than resending them. Requests already submitted keep the
 * snapshot they started with.
 */
class UpdateApprovalWorkflowRequest extends StoreApprovalWorkflowRequest {}
