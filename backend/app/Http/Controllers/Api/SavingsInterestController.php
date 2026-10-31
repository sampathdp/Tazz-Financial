<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalType;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Services\NumberGenerator;
use App\Support\CurrentBranch;
use App\Support\SavingsAccountService;
use App\Support\SavingsInterestCalculator;
use App\Support\SavingsLedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Batch savings interest — computed and previewed per account for a period,
 * then Posted once (needs savings_interest_run authorisation): each account
 * gets a GL-only InterestPosting capitalised to its balance
 * (Dr 5120 / Cr 2050). Voiding reverses the ledger and removes the postings.
 */
class SavingsInterestController extends Controller
{
    public function index(Request $request)
    {
        $q = SavingsInterestRun::with(['branch:id,name,code', 'creator:id,name', 'poster:id,name'])->withCount('transactions')->orderByDesc('id');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return $q->get();
    }

    public function show(SavingsInterestRun $savingsInterestRun)
    {
        $run = $savingsInterestRun->load(['branch:id,name,code', 'creator:id,name', 'poster:id,name']);
        if ($run->status === 'Draft') {
            $preview = $this->compute($run->period_from->format('Y-m-d'), $run->period_to->format('Y-m-d'), $run->branch_id);

            return ['run' => $run, 'preview' => $preview['rows'], 'total_interest' => $preview['total'], 'account_count' => count($preview['rows'])];
        }

        return [
            'run' => $run,
            'postings' => $run->transactions()->with('savingsAccount:id,account_number,customer_id')->with('savingsAccount.customer:id,full_name,code')->get(),
        ];
    }

    /** Ad-hoc preview without persisting a run. */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'branch_id' => 'nullable|exists:branches,id',
        ]);
        $result = $this->compute($data['period_from'], $data['period_to'], $data['branch_id'] ?? null);

        return ['rows' => $result['rows'], 'total_interest' => $result['total'], 'account_count' => count($result['rows'])];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'branch_id' => 'nullable|exists:branches,id',
            'notes' => 'nullable|string',
        ]);

        $run = DB::transaction(function () use ($data, $request) {
            $number = NumberGenerator::next('savings_interest', ['user_id' => $request->user()->id]);
            $run = SavingsInterestRun::create([
                'run_number' => $number,
                'branch_id' => $data['branch_id'] ?? null,
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'status' => 'Draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            NumberGenerator::attach($number, $run);

            return $run;
        });

        return response()->json($run, 201);
    }

    public function destroy(SavingsInterestRun $savingsInterestRun)
    {
        if ($savingsInterestRun->status !== 'Draft') {
            return response()->json(['message' => 'Only a Draft run can be deleted.'], 422);
        }
        $savingsInterestRun->delete();

        return response()->noContent();
    }

    public function post(Request $request, SavingsInterestRun $savingsInterestRun)
    {
        if ($savingsInterestRun->status !== 'Draft') {
            return response()->json(['message' => 'This run is not a Draft.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'savings_interest_run')) {
            return response()->json(['message' => 'Posting a savings interest run needs approval from an authorised approver (savings_interest_run).'], 403);
        }

        $from = $savingsInterestRun->period_from->format('Y-m-d');
        $to = $savingsInterestRun->period_to->format('Y-m-d');
        $computed = $this->compute($from, $to, $savingsInterestRun->branch_id);
        if (empty($computed['rows'])) {
            throw ValidationException::withMessages(['period' => 'No accounts have any interest to post for this period.']);
        }

        DB::transaction(function () use ($savingsInterestRun, $computed, $to, $request) {
            // Another request may have posted this run since the check above.
            if (SavingsInterestRun::lockForUpdate()->findOrFail($savingsInterestRun->id)->status !== 'Draft') {
                throw ValidationException::withMessages(['run' => 'This run is not a Draft.']);
            }
            foreach ($computed['rows'] as $row) {
                $account = SavingsAccount::with('savingsProduct', 'branch')->find($row['account_id']);
                $txn = SavingsAccountService::record($account, [
                    'type' => 'InterestPosting', 'direction' => 'In', 'amount' => $row['interest'], 'txn_date' => $to,
                    'interest_run_id' => $savingsInterestRun->id,
                    'notes' => "Interest {$savingsInterestRun->run_number} ({$row['method']}, basis " . number_format((float) $row['basis_balance'], 2) . ')',
                    'created_by' => $request->user()->id,
                ]);
                $account->update(['last_interest_posted_on' => $to, 'interest_accrued' => 0]);
                unset($txn);
            }

            SavingsLedgerPoster::postInterestRun($savingsInterestRun, (string) $computed['total'], $request->user()->id);

            $savingsInterestRun->update([
                'status' => 'Posted',
                'posted_at' => now(),
                'posted_by' => $request->user()->id,
                'account_count' => count($computed['rows']),
                'total_interest' => $computed['total'],
            ]);
        });

        return $savingsInterestRun->fresh()->load(['branch:id,name,code', 'creator:id,name', 'poster:id,name']);
    }

    public function voidRun(Request $request, SavingsInterestRun $savingsInterestRun)
    {
        if ($savingsInterestRun->status !== 'Posted') {
            return response()->json(['message' => 'Only a Posted run can be voided.'], 422);
        }
        if (!ApprovalType::isAuthorized($request->user(), 'savings_interest_run')) {
            return response()->json(['message' => 'Voiding a savings interest run needs approval from an authorised approver (savings_interest_run).'], 403);
        }

        DB::transaction(function () use ($savingsInterestRun, $request) {
            if (SavingsInterestRun::lockForUpdate()->findOrFail($savingsInterestRun->id)->status !== 'Posted') {
                throw ValidationException::withMessages(['run' => 'Only a Posted run can be voided.']);
            }
            SavingsLedgerPoster::reverseInterestRun($savingsInterestRun, $request->user()->id);

            foreach ($savingsInterestRun->transactions()->where('status', 'Posted')->get() as $txn) {
                $account = SavingsAccount::with('branch')->find($txn->savings_account_id);
                SavingsAccountService::record($account, [
                    'type' => 'Adjustment', 'direction' => 'Out', 'amount' => $txn->amount, 'txn_date' => now()->format('Y-m-d'),
                    'notes' => "Void of interest {$savingsInterestRun->run_number}", 'void_of_id' => $txn->id,
                    'created_by' => $request->user()->id,
                ]);
                $txn->update(['status' => 'Voided']);
            }
            $savingsInterestRun->update(['status' => 'Voided']);
        });

        return $savingsInterestRun->fresh();
    }

    /**
     * @return array{rows: array<int, array{account_id:int, account_number:string, customer:?string, interest:string, basis_balance:string, method:string}>, total: string}
     */
    private function compute(string $from, string $to, ?int $branchId): array
    {
        $q = SavingsAccount::with('customer:id,full_name,code')
            ->whereIn('status', ['Active', 'Dormant'])
            ->where(function ($w) use ($to) {
                $w->whereNull('last_interest_posted_on')->orWhereDate('last_interest_posted_on', '<', $to);
            });
        if ($branchId) {
            $q->where('branch_id', $branchId);
        } else {
            CurrentBranch::applyScope($q);
        }

        $rows = [];
        $total = '0.00';
        foreach ($q->get() as $account) {
            $calc = SavingsInterestCalculator::forAccount($account, $from, $to);
            if (bccomp($calc['interest'], '0.00', 2) <= 0) {
                continue;
            }
            $rows[] = [
                'account_id' => $account->id,
                'account_number' => $account->account_number,
                'customer' => $account->customer?->full_name,
                'interest' => $calc['interest'],
                'basis_balance' => $calc['basis_balance'],
                'method' => $calc['method'],
            ];
            $total = bcadd($total, $calc['interest'], 2);
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
