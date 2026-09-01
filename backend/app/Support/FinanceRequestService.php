<?php

namespace App\Support;

use App\Events\FinanceRequestSettled;
use App\Exceptions\FinancePostingException;
use App\Models\ApprovalRequest;
use App\Models\ApprovalType;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceRequest;
use App\Services\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the finance_request lifecycle: open -> submit -> approve (routed by
 * amount tier) -> execute (through FinancePostingService) -> paid, plus
 * reject / cancel. Source modules call open() and listen for
 * FinanceRequestSettled; they never touch cash.
 */
class FinanceRequestService
{
    /**
     * Creates a request. Pass submit=true to route it for approval
     * immediately (the normal path — a Draft is only for a UI "save for
     * later").
     *
     * @param array{
     *   request_type:string, amount:numeric, branch_id?:?int,
     *   source_module?:?string, source_id?:?int,
     *   payee_type?:?string, payee_id?:?int, payee_name?:?string,
     *   preferred_method?:?string, purpose?:?string, notes?:?string,
     *   priority?:string, meta?:?array, requested_by:int, submit?:bool
     * } $spec
     */
    public static function open(array $spec): FinanceRequest
    {
        if (!in_array($spec['request_type'], FinanceRequest::REQUEST_TYPES, true)) {
            throw new FinancePostingException("Unknown finance request type '{$spec['request_type']}'.");
        }
        if (bccomp((string) $spec['amount'], '0.00', 2) <= 0) {
            throw new FinancePostingException('A finance request amount must be greater than zero.');
        }

        $branchId = $spec['branch_id'] ?? CurrentBranch::forWrite();

        // One open request per source record — mirrors the "already pending"
        // guards the loan/expense controllers use.
        if (!empty($spec['source_module']) && !empty($spec['source_id'])) {
            $open = FinanceRequest::where('source_module', $spec['source_module'])
                ->where('source_id', $spec['source_id'])
                ->whereNotIn('status', ['Rejected', 'Cancelled', 'Completed', 'Reversed'])
                ->exists();
            if ($open) {
                throw new FinancePostingException('A finance request is already open for this record.');
            }
        }

        return DB::transaction(function () use ($spec, $branchId) {
            $branchCode = $branchId ? optional(\App\Models\Branch::find($branchId))->code : null;
            $code = NumberGenerator::next('finance_request', ['branch_code' => $branchCode ?? 'HO']);

            $request = FinanceRequest::create([
                'code' => $code,
                'request_type' => $spec['request_type'],
                'source_module' => $spec['source_module'] ?? null,
                'source_id' => $spec['source_id'] ?? null,
                'branch_id' => $branchId,
                'amount' => $spec['amount'],
                'currency' => $spec['currency'] ?? 'LKR',
                'payee_type' => $spec['payee_type'] ?? null,
                'payee_id' => $spec['payee_id'] ?? null,
                'payee_name' => $spec['payee_name'] ?? null,
                'preferred_method' => $spec['preferred_method'] ?? null,
                'purpose' => $spec['purpose'] ?? null,
                'notes' => $spec['notes'] ?? null,
                'priority' => $spec['priority'] ?? 'Normal',
                'status' => 'Draft',
                'meta' => $spec['meta'] ?? null,
                'requested_by' => $spec['requested_by'],
                'requested_at' => now(),
            ]);
            NumberGenerator::attach($code, $request);

            if ($spec['submit'] ?? true) {
                self::submit($request, $spec['requested_by']);
            }

            return $request->fresh();
        });
    }

    /** Draft -> PendingApproval: creates the shared approval_requests row, routed by tier. */
    public static function submit(FinanceRequest $request, int $userId): FinanceRequest
    {
        if ($request->status !== 'Draft') {
            throw new FinancePostingException('Only a draft finance request can be submitted.');
        }
        $code = $request->approvalCode();
        $type = ApprovalType::where('code', $code)->firstOrFail();
        $route = ApprovalRouter::route($code, $request->branch_id, (float) $request->amount);

        return DB::transaction(function () use ($request, $type, $route, $userId) {
            $approval = ApprovalRequest::create([
                'approval_type_id' => $type->id,
                'subject_id' => $request->id,
                'requested_by' => $userId,
                'requested_at' => now(),
                'reason' => $request->purpose,
                'required_approvals' => $route['required_approvals'],
            ]);
            $request->update([
                'status' => 'PendingApproval',
                'approval_request_id' => $approval->id,
            ]);

            return $request->fresh();
        });
    }

    /** Records one approval slot. Once the tier's threshold is met the request becomes ReadyForPayment. */
    public static function approve(FinanceRequest $request, int $userId): FinanceRequest
    {
        if ($request->status !== 'PendingApproval') {
            throw new FinancePostingException('This request is not awaiting approval.');
        }
        $approval = $request->approvalRequest;
        if (!$approval || $approval->status !== 'PendingApproval') {
            throw new FinancePostingException('No pending approval was found for this request.');
        }
        if (!ApprovalType::isAuthorized(\App\Models\User::findOrFail($userId), $request->approvalCode())) {
            throw new FinancePostingException('You are not an approver for this kind of finance request.');
        }
        if ($approval->requested_by === $userId) {
            throw new FinancePostingException('You cannot approve your own finance request.');
        }

        return DB::transaction(function () use ($request, $approval, $userId) {
            try {
                $complete = $approval->recordApproval($userId);
            } catch (\RuntimeException $e) {
                throw new FinancePostingException($e->getMessage());
            }
            if ($complete) {
                $approval->update(['status' => 'Approved']);
                $request->update([
                    'status' => 'ReadyForPayment',
                    'approved_by' => $userId,
                    'approved_at' => now(),
                ]);
            }

            return $request->fresh();
        });
    }

    public static function reject(FinanceRequest $request, int $userId, ?string $reason = null): FinanceRequest
    {
        if ($request->status !== 'PendingApproval') {
            throw new FinancePostingException('Only a pending finance request can be rejected.');
        }
        $approval = $request->approvalRequest;
        if ($approval && !ApprovalType::isAuthorized(\App\Models\User::findOrFail($userId), $request->approvalCode())) {
            throw new FinancePostingException('You are not an approver for this kind of finance request.');
        }

        DB::transaction(function () use ($request, $approval, $userId, $reason) {
            if ($approval) {
                $approval->update([
                    'status' => 'Rejected',
                    'rejected_by' => $userId,
                    'rejected_at' => now(),
                    'reject_reason' => $reason,
                ]);
            }
            $request->update(['status' => 'Rejected', 'reject_reason' => $reason, 'closed_at' => now()]);
        });

        $request = $request->fresh();
        FinanceRequestSettled::dispatch($request, 'rejected');

        return $request;
    }

    public static function cancel(FinanceRequest $request, int $userId): FinanceRequest
    {
        if (!in_array($request->status, FinanceRequest::CANCELLABLE, true)) {
            throw new FinancePostingException('This request can no longer be cancelled.');
        }

        DB::transaction(function () use ($request) {
            if ($request->approvalRequest && $request->approvalRequest->status === 'PendingApproval') {
                $request->approvalRequest->delete();
            }
            $request->update(['status' => 'Cancelled', 'closed_at' => now()]);
        });

        $request = $request->fresh();
        FinanceRequestSettled::dispatch($request, 'cancelled');

        return $request;
    }

    /**
     * Releases the money. The executor chooses the source account and (for a
     * plain payment) the offset account the GL should debit; a transfer
     * chooses a destination account instead.
     *
     * @param array{
     *   method:string, cash_account_id?:?int, bank_account_id?:?int,
     *   offset_account_id?:?int, dest_cash_account_id?:?int, dest_bank_account_id?:?int,
     *   amount?:numeric, entry_date?:string
     * } $exec
     */
    public static function execute(FinanceRequest $request, array $exec, int $userId): FinanceRequest
    {
        if ($request->status !== 'ReadyForPayment') {
            throw new FinancePostingException('This request is not ready for payment.');
        }
        $approval = $request->approvalRequest;
        if ($approval && $approval->requested_by === $userId && (float) $request->amount > 0) {
            // Segregation of duties — the requester can't also be the executor.
            throw new FinancePostingException('The person who raised a request cannot also execute it.');
        }

        $amount = (string) ($exec['amount'] ?? $request->amount);
        if (bccomp($amount, (string) $request->amount, 2) > 0) {
            throw new FinancePostingException('The executed amount cannot exceed the approved amount.');
        }
        $entryDate = $exec['entry_date'] ?? now()->toDateString();
        $isTransfer = in_array($request->txnType(), ['TRANSFER', 'FLOAT_ISSUE'], true);
        $meta = $request->meta ?? [];

        // For a source-driven request (loan disbursement, expense) the method
        // and the specific bank account were fixed when the request was
        // raised — the executor only confirms / picks the cash till. Fall
        // back to those before the generic defaults.
        $method = $exec['method'] ?? $request->preferred_method ?? 'Cash';
        if ($method === 'Cheque') {
            $method = 'Bank';
        }
        $bankId = $exec['bank_account_id'] ?? ($method === 'Bank' ? ($meta['source_bank_account_id'] ?? null) : null);

        [$srcType, $srcId, $srcLeaf] = self::resolveAccount($method, $exec['cash_account_id'] ?? null, $bankId);
        $movements = [['account_type' => $srcType, 'account_id' => $srcId, 'delta' => '-' . $amount]];

        if ($isTransfer) {
            $destCash = $exec['dest_cash_account_id'] ?? null;
            $destBank = $exec['dest_bank_account_id'] ?? null;
            if (!$destCash && !$destBank) {
                throw new FinancePostingException('A transfer needs a destination account.');
            }
            [$dType, $dId, $dLeaf] = self::resolveAccount($destCash ? 'Cash' : 'Bank', $destCash, $destBank);
            $movements[] = ['account_type' => $dType, 'account_id' => $dId, 'delta' => $amount];
            $glLines = [
                ['account_id' => $dLeaf->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $srcLeaf->id, 'debit' => 0, 'credit' => $amount],
            ];
        } else {
            $offsetId = $exec['offset_account_id'] ?? $meta['offset_account_id'] ?? null;
            if (!$offsetId) {
                throw new FinancePostingException('Choose the account this payment should be posted against.');
            }
            $glLines = [
                ['account_id' => (int) $offsetId, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $srcLeaf->id, 'debit' => 0, 'credit' => $amount],
            ];
        }

        // A source-keyed GL entry (loan disbursement stays source_type='Loan')
        // when the request carries it — so LoanController::purge() /
        // reverseDisbursement() still find the journal entry.
        $glSource = isset($meta['gl_source_type'])
            ? ['gl_source_type' => $meta['gl_source_type'], 'gl_source_id' => $meta['gl_source_id'] ?? null]
            : [];

        DB::transaction(function () use ($request, $userId, $amount, $entryDate, $movements, $glLines, $glSource, $srcType, $srcId) {
            $request->update(['status' => 'Processing']);

            $txn = FinancePostingService::execute($glSource + [
                'txn_type' => $request->txnType(),
                'amount' => $amount,
                'entry_date' => $entryDate,
                'branch_id' => $request->branch_id,
                'movements' => $movements,
                'gl_lines' => $glLines,
                'description' => $request->purpose ?? "{$request->request_type} - {$request->code}",
                'source_module' => $request->source_module,
                'source_id' => $request->source_id,
                'finance_request_id' => $request->id,
                'idempotency_key' => "finance-request-{$request->id}",
                'requested_by' => $request->requested_by,
                'approved_by' => $request->approved_by,
                'executed_by' => $userId,
            ]);

            $request->update([
                'status' => 'Paid',
                'executed_by' => $userId,
                'executed_at' => now(),
                'closed_at' => now(),
                'finance_transaction_id' => $txn->id,
                'source_cash_account_id' => $srcType === 'cash' ? $srcId : null,
                'source_bank_account_id' => $srcType === 'bank' ? $srcId : null,
            ]);
        });

        $request = $request->fresh();
        FinanceRequestSettled::dispatch($request, 'paid');

        return $request;
    }

    /** @return array{0:string,1:int,2:ChartOfAccount} */
    private static function resolveAccount(string $method, ?int $cashId, ?int $bankId): array
    {
        if ($method === 'Cash') {
            if (!$cashId) {
                throw ValidationException::withMessages(['cash_account_id' => 'Choose the cash account.']);
            }
            $cash = CashAccount::findOrFail($cashId);

            return ['cash', (int) $cash->id, ChartOfAccount::forCashTill($cash)];
        }
        if (!$bankId) {
            throw ValidationException::withMessages(['bank_account_id' => 'Choose the bank account.']);
        }
        $bank = BankAccount::findOrFail($bankId);

        return ['bank', (int) $bank->id, ChartOfAccount::forBankAccount($bank)];
    }
}
