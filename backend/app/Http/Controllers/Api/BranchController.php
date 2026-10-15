<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index()
    {
        return Branch::with('parent:id,name,code')->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $branch = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $branch = Branch::create($data + ['status' => $data['status'] ?? 'Active', 'branch_type' => $data['branch_type'] ?? 'Branch']);
            // The branch's main till and its ledger account, so nothing can fail later for want of one.
            \App\Support\BranchProvisioner::provision($branch);

            return $branch;
        });

        return response()->json($branch->load('parent:id,name,code'), 201);
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $this->validated($request, $branch);
        if (isset($data['parent_id']) && $data['parent_id'] === $branch->id) {
            return response()->json(['message' => 'A branch cannot be its own parent.'], 422);
        }
        if (($data['status'] ?? $branch->status) === 'Inactive' && $branch->status === 'Active') {
            if (\App\Models\CashAccount::where('branch_id', $branch->id)->where('status', 'Active')->where('current_balance', '!=', 0)->exists()) {
                return response()->json(['message' => 'This branch still holds cash (a till, float or petty-cash balance). Settle and transfer it out before deactivating.'], 422);
            }
            if (\App\Models\CashSession::where('branch_id', $branch->id)->where('status', 'Open')->exists()) {
                return response()->json(['message' => 'This branch has an open cash session. Close and verify it before deactivating.'], 422);
            }
        }
        $branch->update($data);

        return $branch->load('parent:id,name,code');
    }

    public function destroy(Branch $branch)
    {
        if ($branch->is_head_office) {
            return response()->json(['message' => 'The head office branch cannot be deleted.'], 422);
        }
        if ($branch->users()->exists()) {
            return response()->json(['message' => 'This branch has users assigned and cannot be deleted.'], 422);
        }
        if ($branch->centers()->exists()) {
            return response()->json(['message' => 'This branch has centers under it and cannot be deleted. Delete or move them first.'], 422);
        }
        // The default till made with the branch can go with it, but only while it is untouched.
        $tills = \App\Models\CashAccount::withoutGlobalScopes()->where('branch_id', $branch->id)->get();
        foreach ($tills as $till) {
            $used = (float) $till->current_balance !== 0.0
                || $till->entries()->exists()
                || ($till->chart_of_account_id && \App\Models\JournalLine::where('account_id', $till->chart_of_account_id)->exists());
            if ($used) {
                return response()->json(['message' => "This branch has a till (\"{$till->name}\") with transactions and cannot be deleted. Deactivate the branch instead."], 422);
            }
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($tills, $branch) {
            foreach ($tills as $till) {
                $leafId = $till->chart_of_account_id;
                $till->delete();
                if ($leafId) {
                    \App\Models\ChartOfAccount::whereKey($leafId)->where('is_system', false)->delete();
                }
            }
            $branch->delete();
        });

        return response()->noContent();
    }

    private function validated(Request $request, ?Branch $branch = null): array
    {
        $type = $request->input('branch_type', $branch?->branch_type ?? 'Branch');

        $data = $request->validate([
            'name' => 'required|string|max:128',
            'code' => ['required', 'string', 'max:16', Rule::unique('branches', 'code')->ignore($branch?->id)],
            'branch_type' => [Rule::in(['Branch', 'Center'])],
            'parent_id' => [
                $type === 'Center' ? 'required' : 'nullable',
                'exists:branches,id',
                Rule::notIn([$branch?->id]),
            ],
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:190',
            'is_head_office' => 'boolean',
            'funding_borrow_limit' => 'nullable|numeric|min:0',
            'status' => [Rule::in(['Active', 'Inactive'])],
        ]);

        if (!empty($data['parent_id'])) {
            $parent = Branch::find($data['parent_id']);
            if (!$parent || $parent->branch_type !== 'Branch') {
                return $this->fail('A center must be linked to a Branch, not another Center.');
            }
        }
        if (($data['branch_type'] ?? $type) !== 'Center') {
            $data['parent_id'] = null;
        }

        return $data;
    }

    private function fail(string $message): never
    {
        abort(response()->json(['message' => $message], 422));
    }
}
