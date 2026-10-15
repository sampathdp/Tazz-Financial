<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\BranchFundingAgreement;
use App\Models\BranchFundingTransaction;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Support\FinancePostingService;
use App\Support\LedgerPoster;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts branch-to-branch funding through Head Office (hub and spoke). Every event is three entries, one per
 * branch, each balanced on its own; the Head Office entry is the main one and the two branch entries link to it
 * (related_entry_id), so reversing the Head Office entry reverses all three.
 *
 *   Disbursement P   lender   Dr 1410 / Cr lender cash      HO  Dr 1411 / Cr 2411      borrower  Dr cash / Cr 2410
 *   Interest I       lender   Dr 1410 / Cr 4040             HO  Dr 1411 / Cr 2411      borrower  Dr 5130 / Cr 2410
 *   Repayment T      lender   Dr cash / Cr 1410             HO  Dr 2411 / Cr 1411      borrower  Dr 2410 / Cr cash
 *
 * Must run inside the caller's DB transaction, like LedgerPoster.
 */
class BranchFundingService
{
    public static function headOfficeId(): int
    {
        $id = Branch::where('is_head_office', true)->value('id');
        if (!$id) {
            throw ValidationException::withMessages(['branch' => 'No branch is marked as Head Office, so branch funding cannot be routed.']);
        }

        return (int) $id;
    }

    /** What a branch currently owes through Active funding agreements (principal plus unpaid interest). */
    public static function owedBy(int $branchId, ?int $exceptAgreementId = null): string
    {
        $q = BranchFundingAgreement::where('borrower_branch_id', $branchId)->where('status', 'Active');
        if ($exceptAgreementId) {
            $q->where('id', '!=', $exceptAgreementId);
        }

        return bcadd((string) $q->sum('outstanding_principal'), (string) $q->sum('accrued_interest_unpaid'), 2);
    }

    /** Ledger balance (debit minus credit) of one account across every branch. */
    public static function accountBalance(int $accountId): string
    {
        $row = DB::table('journal_lines')->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')->first();

        return bcsub((string) $row->d, (string) $row->c, 2);
    }

    /** Posts the funding being paid out: lender cash to borrower cash, via Head Office. */
    public static function disburse(BranchFundingAgreement $a, string $date, ?int $userId): BranchFundingTransaction
    {
        $p = number_format((float) $a->principal_amount, 2, '.', '');
        $main = self::postTriple($a, $date, "Branch funding {$a->code}: disbursement", $userId,
            lender: [self::dr('1410', $p), self::cr($a->lender_account_id, $p)],
            ho: [self::dr('1411', $p), self::cr('2411', $p)],
            borrower: [self::dr($a->borrower_account_id, $p), self::cr('2410', $p)],
            lenderMove: '-' . $p, borrowerMove: $p);
        $a->update([
            'status' => 'Active', 'outstanding_principal' => $p, 'disbursed_on' => $date,
            'interest_accrued_through' => $date, 'accrued_interest_unpaid' => '0.00',
        ]);

        return self::record($a, 'Disbursement', $date, $p, '0.00', $main, $userId);
    }

    /** Interest on the outstanding principal from the last accrual date up to $through (simple, actual/365). */
    public static function accrue(BranchFundingAgreement $a, string $through, ?int $userId): ?BranchFundingTransaction
    {
        if ($a->status !== 'Active' || !$a->interest_accrued_through) {
            return null;
        }
        $from = Carbon::parse($a->interest_accrued_through)->startOfDay();
        $to = Carbon::parse($through)->startOfDay();
        $days = (int) $from->diffInDays($to, false);
        if ($days <= 0) {
            return null;
        }
        $interest = number_format((float) $a->outstanding_principal * ((float) $a->interest_rate_percent / 100) * $days / 365, 2, '.', '');
        $a->update(['interest_accrued_through' => $to->toDateString()]);
        if (bccomp($interest, '0', 2) <= 0) {
            return null;
        }
        $main = self::postTriple($a, $to->toDateString(), "Branch funding {$a->code}: interest to {$to->toDateString()}", $userId,
            lender: [self::dr('1410', $interest), self::cr('4040', $interest)],
            ho: [self::dr('1411', $interest), self::cr('2411', $interest)],
            borrower: [self::dr('5130', $interest), self::cr('2410', $interest)]);
        $a->update(['accrued_interest_unpaid' => bcadd((string) $a->accrued_interest_unpaid, $interest, 2)]);

        return self::record($a, 'Interest', $to->toDateString(), '0.00', $interest, $main, $userId);
    }

    /** Borrower pays back $amount: interest first, then principal. Interest is accrued up to $date beforehand. */
    public static function repay(BranchFundingAgreement $a, string $amount, string $date, ?int $userId): BranchFundingTransaction
    {
        self::accrue($a, $date, $userId);
        $a->refresh();
        $interestDue = number_format((float) $a->accrued_interest_unpaid, 2, '.', '');
        $principalDue = number_format((float) $a->outstanding_principal, 2, '.', '');
        $totalDue = bcadd($interestDue, $principalDue, 2);
        if (bccomp($amount, $totalDue, 2) > 0) {
            throw ValidationException::withMessages(['amount' => "The most that can be repaid on {$a->code} is {$totalDue}."]);
        }
        $interest = bccomp($amount, $interestDue, 2) < 0 ? $amount : $interestDue;
        $principal = bcsub($amount, $interest, 2);

        $main = self::postTriple($a, $date, "Branch funding {$a->code}: repayment", $userId,
            lender: [self::dr($a->lender_account_id, $amount), self::cr('1410', $amount)],
            ho: [self::dr('2411', $amount), self::cr('1411', $amount)],
            borrower: [self::dr('2410', $amount), self::cr($a->borrower_account_id, $amount)],
            lenderMove: $amount, borrowerMove: '-' . $amount);
        $newPrincipal = bcsub($principalDue, $principal, 2);
        $newInterest = bcsub($interestDue, $interest, 2);
        $a->update([
            'outstanding_principal' => $newPrincipal, 'accrued_interest_unpaid' => $newInterest,
            'status' => bccomp($newPrincipal, '0', 2) === 0 && bccomp($newInterest, '0', 2) === 0 ? 'Closed' : 'Active',
        ]);

        return self::record($a, 'Repayment', $date, $principal, $interest, $main, $userId);
    }

    private static function dr($account, string $amount): array
    {
        return ['account' => $account, 'debit' => $amount, 'credit' => 0];
    }

    private static function cr($account, string $amount): array
    {
        return ['account' => $account, 'debit' => 0, 'credit' => $amount];
    }

    private static function record(BranchFundingAgreement $a, string $kind, string $date, string $principal, string $interest, JournalEntry $main, ?int $userId): BranchFundingTransaction
    {
        return BranchFundingTransaction::create([
            'agreement_id' => $a->id, 'kind' => $kind, 'txn_date' => $date, 'principal_amount' => $principal,
            'interest_amount' => $interest, 'journal_entry_id' => $main->id, 'created_by' => $userId,
        ]);
    }

    /** Posts Head Office's entry, then the lender's and borrower's, and links them. Returns the Head Office entry. */
    private static function postTriple(BranchFundingAgreement $a, string $date, string $description, ?int $userId, array $lender, array $ho, array $borrower, ?string $lenderMove = null, ?string $borrowerMove = null): JournalEntry
    {
        $hoId = self::headOfficeId();
        $meta = fn (int $branch) => ['source_type' => 'BranchFunding', 'source_id' => $a->id, 'description' => $description, 'branch_id' => $branch, 'posted_by' => $userId];
        $resolve = fn (array $lines) => array_map(function (array $l) {
            $id = is_int($l['account']) ? $l['account'] : ChartOfAccount::system($l['account'])->id;

            return ['account_id' => $id, 'debit' => $l['debit'], 'credit' => $l['credit']];
        }, $lines);

        $main = LedgerPoster::post($resolve($ho), $date, $meta($hoId));
        foreach ([[$a->lender_branch_id, $lender, $a->lender_account_id, $lenderMove], [$a->borrower_branch_id, $borrower, $a->borrower_account_id, $borrowerMove]] as [$branch, $lines, $cashChartId, $move]) {
            $entry = $move === null
                ? LedgerPoster::post($resolve($lines), $date, $meta((int) $branch))
                : self::postCashLeg($resolve($lines), $date, $meta((int) $branch), (int) $cashChartId, $move, $a);
            $entry->update(['related_entry_id' => $main->id, 'counterparty_branch_id' => $hoId]);
        }

        return $main;
    }

    /**
     * A branch entry that moves a till or bank balance goes through the one money writer, so the account's
     * current_balance, the cashbook and finance_transactions stay in step with the ledger.
     */
    private static function postCashLeg(array $lines, string $date, array $meta, int $chartAccountId, string $delta, BranchFundingAgreement $a): JournalEntry
    {
        if ($till = CashAccount::withoutGlobalScopes()->where('chart_of_account_id', $chartAccountId)->first()) {
            $movement = ['account_type' => 'cash', 'account_id' => (int) $till->id, 'delta' => $delta];
        } elseif ($bank = BankAccount::withoutGlobalScopes()->where('chart_of_account_id', $chartAccountId)->first()) {
            $movement = ['account_type' => 'bank', 'account_id' => (int) $bank->id, 'delta' => $delta];
        } else {
            throw ValidationException::withMessages(['account' => 'The funding account is no longer a cash or bank account.']);
        }
        $txn = FinancePostingService::execute([
            'txn_type' => 'TRANSFER',
            'amount' => ltrim($delta, '-'),
            'entry_date' => $date,
            'branch_id' => $meta['branch_id'],
            'movements' => [$movement],
            'gl_lines' => $lines,
            'gl_source_type' => 'BranchFunding',
            'gl_source_id' => $a->id,
            'gl_description' => $meta['description'],
            'description' => $meta['description'],
            'source_module' => 'BranchFunding',
            'source_id' => $a->id,
            'executed_by' => $meta['posted_by'],
        ]);

        return $txn->journalEntry;
    }
}
