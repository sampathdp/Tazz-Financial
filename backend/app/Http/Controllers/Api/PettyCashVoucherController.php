<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use App\Models\PettyCashFund;
use App\Models\PettyCashVoucher;
use App\Services\NumberGenerator;
use App\Support\LedgerPoster;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PettyCashVoucherController extends Controller
{
    public function index(Request $request)
    {
        return PettyCashVoucher::with([
            'fund:id,code,name,branch_id', 'fund.branch:id,name,code',
            'category:id,name', 'vendor:id,name', 'payer:id,name', 'voider:id,name',
        ])
            ->when($request->query('petty_cash_fund_id'), fn ($q, $f) => $q->where('petty_cash_fund_id', $f))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Records a small spend straight out of the float: Dr the category's
     * expense account, Cr the fund's own GL leaf — the mirror image of a
     * PettyCashReplenishment's posting, and the same shape as
     * ExpenseController::pay() minus the request/approve steps an imprest
     * float exists to skip.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'petty_cash_fund_id' => 'required|exists:petty_cash_funds,id',
            'expense_category_id' => 'required|exists:expense_categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'spent_at' => 'nullable|date',
        ]);

        $fund = PettyCashFund::findOrFail($data['petty_cash_fund_id']);
        if ($fund->status !== 'Active') {
            return response()->json(['message' => 'This float is inactive and cannot be spent from.'], 422);
        }
        if (bccomp((string) $fund->current_balance, (string) $data['amount'], 2) < 0) {
            return response()->json(['message' => "Insufficient petty cash balance — this float only holds {$fund->current_balance}."], 422);
        }
        $category = ExpenseCategory::findOrFail($data['expense_category_id']);
        if (!$category->chart_of_account_id) {
            return response()->json(['message' => "The expense category \"{$category->name}\" has no linked chart-of-accounts entry yet — set one in Masters > Expense Categories first."], 422);
        }

        $voucher = DB::transaction(function () use ($fund, $category, $data, $request) {
            $spentAt = $data['spent_at'] ?? now()->toDateString();

            $fundLocked = PettyCashFund::lockForUpdate()->findOrFail($fund->id);
            $outAccount = ChartOfAccount::forPettyCashFund($fundLocked);
            $fundLocked->update(['current_balance' => bcsub((string) $fundLocked->current_balance, (string) $data['amount'], 2)]);

            $code = NumberGenerator::next('petty_cash_voucher', ['branch_code' => $fundLocked->branch->code]);
            $voucher = PettyCashVoucher::create([
                'code' => $code,
                'petty_cash_fund_id' => $fundLocked->id,
                'expense_category_id' => $category->id,
                'vendor_id' => $data['vendor_id'] ?? null,
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
                'spent_at' => $spentAt,
                'paid_by' => $request->user()->id,
                'status' => 'Issued',
            ]);
            NumberGenerator::attach($code, $voucher);

            $entry = LedgerPoster::post([
                ['account_id' => $category->chart_of_account_id, 'debit' => $data['amount'], 'credit' => 0, 'description' => $data['description'] ?? null],
                ['account_id' => $outAccount->id, 'debit' => 0, 'credit' => $data['amount']],
            ], Carbon::parse($spentAt)->toDateString(), [
                'source_type' => 'PettyCashVoucher',
                'source_id' => $voucher->id,
                'description' => "Petty cash spend {$voucher->code}",
                'branch_id' => $fundLocked->branch_id,
                'posted_by' => $request->user()->id,
            ]);
            $voucher->update(['journal_entry_id' => $entry->id]);

            return $voucher;
        });

        return response()->json($voucher->load('journalEntry', 'fund:id,code,name', 'category:id,name'), 201);
    }

    public function destroy(Request $request, PettyCashVoucher $pettyCashVoucher)
    {
        if ($pettyCashVoucher->status === 'Voided') {
            return response()->json(['message' => 'This voucher is already voided.'], 422);
        }
        $data = $request->validate(['void_reason' => 'nullable|string|max:255']);

        DB::transaction(function () use ($pettyCashVoucher, $data, $request) {
            $fund = PettyCashFund::lockForUpdate()->findOrFail($pettyCashVoucher->petty_cash_fund_id);
            $fund->update(['current_balance' => bcadd((string) $fund->current_balance, (string) $pettyCashVoucher->amount, 2)]);

            if ($pettyCashVoucher->journal_entry_id) {
                LedgerPoster::reverse($pettyCashVoucher->journalEntry, "Voided: {$pettyCashVoucher->code}", $request->user()->id);
            }

            $pettyCashVoucher->update([
                'status' => 'Voided',
                'void_reason' => $data['void_reason'] ?? null,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
            ]);
        });

        return $pettyCashVoucher->fresh()->load('voider:id,name');
    }
}
