<?php

namespace App\Events;

use App\Models\FinanceRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a finance_request reaches a terminal-ish state that a source
 * module cares about: Paid (money released), Rejected, or Cancelled. A
 * source module (Loans, Expenses, ...) listens for this — keyed by
 * $request->source_module / source_id — to advance its own record (loan ->
 * Active, expense -> Paid, or back to a re-requestable state on reject).
 *
 * No listeners are registered yet; the Loan / Expense integrations (Phase 3
 * and 4) add them.
 */
class FinanceRequestSettled
{
    use Dispatchable;

    public function __construct(
        public FinanceRequest $request,
        public string $outcome, // 'paid' | 'rejected' | 'cancelled'
    ) {
    }
}
