<?php

namespace App\Support;

use App\Exceptions\FinancePostingException;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\CashAccount;
use App\Models\CashFloatSettlement;
use App\Models\ChartOfAccount;
use App\Models\FinanceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cashier float lifecycle. A float is a cash_accounts row
 * (account_type = CashierFloat); this service issues one (a transfer from
 * the branch till, routed through a finance request), then settles it —
 * counting the cash, posting any shortage / excess, and returning the
 * remainder to the till.
 */
class CashFloatService
{
    /**
     * Issue (or top up) a float for a custodian from a branch till. Returns
     * the finance request that funds it — Finance approves and executes it,
     * and RecordFloatFundingOnSettled bumps the settlement's issued_total.
     */
    public static function issue(int $homeCashAccountId, int $custodianId, string $amount, int $requestedBy, ?string $notes = null): FinanceRequest
    {
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new FinancePostingException('A float amount must be greater than zero.');
        }
        $home = CashAccount::findOrFail($homeCashAccountId);
        if (!in_array($home->account_type, CashAccount::PRIMARY_TYPES, true)) {
            throw new FinancePostingException('A float can only be issued from a branch or head-office till.');
        }
        $custodian = User::findOrFail($custodianId);

        return DB::transaction(function () use ($home, $custodian, $amount, $requestedBy, $notes) {
            $float = CashAccount::where('account_type', 'CashierFloat')
                ->where('responsible_user_id', $custodian->id)
                ->where('parent_cash_account_id', $home->id)
                ->where('status', 'Active')
                ->first();

            if (!$float) {
                $float = CashAccount::create([
                    'code' => 'FLT-' . strtoupper(substr($custodian->name, 0, 3)) . '-' . $custodian->id,
                    'branch_id' => $home->branch_id,
                    'account_type' => 'CashierFloat',
                    'responsible_user_id' => $custodian->id,
                    'parent_cash_account_id' => $home->id,
                    'name' => "Float - {$custodian->name}",
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'status' => 'Active',
                ]);
            }

            $settlement = CashFloatSettlement::firstOrCreate(
                ['cash_account_id' => $float->id, 'status' => 'Open'],
                [
                    'home_cash_account_id' => $home->id,
                    'custodian_id' => $custodian->id,
                    'opened_by' => $requestedBy,
                    'opened_at' => now(),
                    'notes' => $notes,
                ]
            );

            return FinanceRequestService::open([
                'request_type' => 'CashFloat',
                'amount' => $amount,
                'branch_id' => $home->branch_id,
                'source_module' => 'CashFloat',
                'source_id' => $float->id,
                'payee_type' => 'User',
                'payee_id' => $custodian->id,
                'payee_name' => $custodian->name,
                'purpose' => "Cash float for {$custodian->name}",
                'notes' => $notes,
                'requested_by' => $requestedBy,
                'meta' => [
                    'source_cash_account_id' => $home->id,
                    'dest_cash_account_id' => $float->id,
                    'float_settlement_id' => $settlement->id,
                ],
            ]);
        });
    }

    /** Ask for the float to be settled — opens a maker-checker approval (code finance_float_settlement) on the settlement row. */
    public static function requestSettlement(int $floatAccountId, int $requestedBy): CashFloatSettlement
    {
        $settlement = CashFloatSettlement::where('cash_account_id', $floatAccountId)->where('status', 'Open')->firstOr(function () {
            throw new FinancePostingException('This float has no open settlement to close.');
        });
        $type = ApprovalType::where('code', 'finance_float_settlement')->firstOrFail();

        return DB::transaction(function () use ($settlement, $type, $requestedBy) {
            if (CashFloatSettlement::lockForUpdate()->findOrFail($settlement->id)->status !== 'Open') {
                throw new FinancePostingException('A settlement has already been requested for this float.');
            }
            $approval = ApprovalRequest::create([
                'approval_type_id' => $type->id,
                'subject_id' => $settlement->id,
                'requested_by' => $requestedBy,
                'requested_at' => now(),
                'required_approvals' => $type->required_approvals,
            ]);
            $settlement->update(['status' => 'SettlementRequested', 'approval_request_id' => $approval->id]);

            return $settlement->fresh();
        });
    }

    /**
     * Settle the float: count it, post the shortage / excess against 5310 /
     * 4905, return the counted cash to the home till, and close the float.
     * Needs the finance_float_settlement approval to be satisfied.
     */
    public static function settle(int $floatAccountId, string $countedAmount, int $userId): CashFloatSettlement
    {
        $settlement = CashFloatSettlement::where('cash_account_id', $floatAccountId)
            ->where('status', 'SettlementRequested')->firstOr(function () {
                throw new FinancePostingException('This float is not awaiting settlement.');
            });
        $approval = $settlement->approvalRequest;
        if (!$approval || $approval->status !== 'PendingApproval') {
            throw new FinancePostingException('No pending settlement approval was found for this float.');
        }
        if (!ApprovalType::isAuthorized(User::findOrFail($userId), 'finance_float_settlement')) {
            throw new FinancePostingException('You are not an approver for float settlements.');
        }
        if ($approval->requested_by === $userId || (int) $settlement->custodian_id === $userId) {
            throw new FinancePostingException('The float holder / requester cannot verify their own settlement.');
        }
        if (bccomp($countedAmount, '0.00', 2) < 0) {
            throw new FinancePostingException('The counted amount cannot be negative.');
        }

        return DB::transaction(function () use ($settlement, $countedAmount, $userId, $approval) {
            // Lock + re-check: two approvers acting together must settle (and move the cash) once.
            $approval->lockPending();
            if (CashFloatSettlement::lockForUpdate()->findOrFail($settlement->id)->status !== 'SettlementRequested') {
                throw new FinancePostingException('This float is not awaiting settlement.');
            }
            $complete = $approval->recordApproval($userId);
            if (!$complete) {
                return $settlement->fresh(); // needs another approver
            }

            $float = CashAccount::lockForUpdate()->findOrFail($settlement->cash_account_id);
            $home = CashAccount::findOrFail($settlement->home_cash_account_id);
            $floatLeaf = ChartOfAccount::forCashTill($float);
            $expected = (string) $float->current_balance;
            $variance = bcsub($countedAmount, $expected, 2);
            $shortage = bccomp($variance, '0.00', 2) < 0 ? bcsub('0.00', $variance, 2) : '0.00';
            $excess = bccomp($variance, '0.00', 2) > 0 ? $variance : '0.00';

            if (bccomp($shortage, '0.00', 2) > 0) {
                FinancePostingService::execute([
                    'txn_type' => 'ADJUSTMENT', 'amount' => $shortage, 'entry_date' => now()->toDateString(),
                    'branch_id' => $float->branch_id,
                    'movements' => [['account_type' => 'cash', 'account_id' => $float->id, 'delta' => '-' . $shortage]],
                    'gl_lines' => [
                        ['account_id' => ChartOfAccount::system('5310')->id, 'debit' => $shortage, 'credit' => 0],
                        ['account_id' => $floatLeaf->id, 'debit' => 0, 'credit' => $shortage],
                    ],
                    'description' => "Float shortage - {$float->name}",
                    'source_module' => 'CashFloat', 'source_id' => $float->id, 'executed_by' => $userId,
                ]);
            } elseif (bccomp($excess, '0.00', 2) > 0) {
                FinancePostingService::execute([
                    'txn_type' => 'ADJUSTMENT', 'amount' => $excess, 'entry_date' => now()->toDateString(),
                    'branch_id' => $float->branch_id,
                    'movements' => [['account_type' => 'cash', 'account_id' => $float->id, 'delta' => $excess]],
                    'gl_lines' => [
                        ['account_id' => $floatLeaf->id, 'debit' => $excess, 'credit' => 0],
                        ['account_id' => ChartOfAccount::system('4905')->id, 'debit' => 0, 'credit' => $excess],
                    ],
                    'description' => "Float overage - {$float->name}",
                    'source_module' => 'CashFloat', 'source_id' => $float->id, 'executed_by' => $userId,
                ]);
            }

            // Return the (now reconciled) cash to the home till.
            $returned = (string) $float->fresh()->current_balance;
            if (bccomp($returned, '0.00', 2) > 0) {
                FinancePostingService::execute([
                    'txn_type' => 'FLOAT_RETURN', 'amount' => $returned, 'entry_date' => now()->toDateString(),
                    'branch_id' => $float->branch_id,
                    'movements' => [
                        ['account_type' => 'cash', 'account_id' => $float->id, 'delta' => '-' . $returned],
                        ['account_type' => 'cash', 'account_id' => $home->id, 'delta' => $returned],
                    ],
                    'gl_lines' => [
                        ['account_id' => ChartOfAccount::forCashTill($home)->id, 'debit' => $returned, 'credit' => 0],
                        ['account_id' => $floatLeaf->id, 'debit' => 0, 'credit' => $returned],
                    ],
                    'description' => "Float returned - {$float->name}",
                    'source_module' => 'CashFloat', 'source_id' => $float->id, 'executed_by' => $userId,
                ]);
            }

            $settlement->update([
                'expected_amount' => $expected,
                'counted_amount' => $countedAmount,
                'variance' => $variance,
                'shortage' => $shortage,
                'excess' => $excess,
                'returned_total' => $returned,
                'status' => 'Settled',
                'settled_by' => $userId,
                'closed_at' => now(),
            ]);
            $approval->update(['status' => 'Approved']);
            $float->update(['status' => 'Inactive']);

            return $settlement->fresh();
        });
    }
}
