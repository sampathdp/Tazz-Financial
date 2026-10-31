<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FinancePostingException;
use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use App\Models\Branch;
use App\Models\FixedAsset;
use App\Services\NumberGenerator;
use App\Support\FixedAssetDepreciator;
use App\Support\FixedAssetLedgerPoster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The fixed-asset register: acquire (paid purchase or opening balance), monthly straight-line depreciation
 * and disposal. All postings go through FixedAssetLedgerPoster, so the books and the register cannot drift.
 */
class FixedAssetController extends Controller
{
    private const RELATIONS = ['category:id,name,asset_account_id', 'branch:id,name,code', 'custodian:id,staff_code,name', 'vendor:id,name'];

    private const PAYMENT_RULES = [
        'payment_method' => 'required_if:acquisition_type,Purchase|nullable|in:Cash,Bank,Cheque',
        'cash_account_id' => 'required_if:payment_method,Cash|nullable|exists:cash_accounts,id',
        'bank_account_id' => 'required_if:payment_method,Bank,Cheque|nullable|exists:bank_accounts,id',
    ];

    public function index(Request $request)
    {
        $q = FixedAsset::with(self::RELATIONS)->with('disposal')->orderByDesc('id');
        foreach (['status', 'asset_category_id', 'branch_id'] as $filter) {
            if ($request->filled($filter)) {
                $q->where($filter, $request->input($filter));
            }
        }
        if ($search = $request->input('search')) {
            $q->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")->orWhere('serial_no', 'like', "%{$search}%"));
        }

        return $q->get();
    }

    public function show(FixedAsset $fixedAsset)
    {
        return $fixedAsset->load(self::RELATIONS)->load('depreciations', 'disposal');
    }

    public function store(Request $request)
    {
        $data = $request->validate(array_merge([
            'name' => 'required|string|max:150',
            'asset_category_id' => 'required|exists:asset_categories,id',
            'branch_id' => 'required|exists:branches,id',
            'description' => 'nullable|string|max:255',
            'serial_no' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:150',
            'custodian_staff_id' => 'nullable|exists:staff,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'invoice_no' => 'nullable|string|max:64',
            'purchase_date' => 'required|date|before_or_equal:today',
            'in_service_date' => 'nullable|date|after_or_equal:purchase_date',
            'cost' => 'required|numeric|min:0.01',
            'residual_value' => 'nullable|numeric|min:0',
            'useful_life_months' => 'nullable|integer|min:1|max:1200',
            'acquisition_type' => 'required|in:Purchase,Opening',
            // Opening only: what was already written off before the system started, and up to which month-end.
            'opening_accumulated_depreciation' => 'nullable|numeric|min:0',
            'opening_depreciated_through' => 'required_with:opening_accumulated_depreciation|nullable|date',
        ], self::PAYMENT_RULES));

        $category = AssetCategory::findOrFail($data['asset_category_id']);
        if ($category->status !== 'Active') {
            throw ValidationException::withMessages(['asset_category_id' => 'This asset category is inactive.']);
        }
        $cost = (float) $data['cost'];
        $residual = isset($data['residual_value']) ? (float) $data['residual_value'] : round($cost * (float) $category->default_residual_percent / 100, 2);
        if ($residual >= $cost) {
            throw ValidationException::withMessages(['residual_value' => 'Residual value must be less than the cost.']);
        }
        $opening = $data['acquisition_type'] === 'Opening';
        $accumulated = $opening ? (float) ($data['opening_accumulated_depreciation'] ?? 0) : 0;
        if ($accumulated > $cost - $residual) {
            throw ValidationException::withMessages(['opening_accumulated_depreciation' => 'Opening depreciation cannot exceed cost less residual value.']);
        }
        $branch = Branch::findOrFail($data['branch_id']);

        $asset = DB::transaction(function () use ($data, $category, $cost, $residual, $accumulated, $opening, $branch, $request) {
            $code = NumberGenerator::next('fixed_asset', ['branch_code' => $branch->code]);
            $life = (int) ($data['useful_life_months'] ?? $category->default_useful_life_months);
            $throughDate = $opening && isset($data['opening_depreciated_through'])
                ? \Illuminate\Support\Carbon::parse($data['opening_depreciated_through'])->endOfMonth()->toDateString() : null;

            $asset = FixedAsset::create([
                'code' => $code,
                'name' => $data['name'],
                'asset_category_id' => $category->id,
                'branch_id' => $branch->id,
                'description' => $data['description'] ?? null,
                'serial_no' => $data['serial_no'] ?? null,
                'location' => $data['location'] ?? null,
                'custodian_staff_id' => $data['custodian_staff_id'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'invoice_no' => $data['invoice_no'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'in_service_date' => $data['in_service_date'] ?? $data['purchase_date'],
                'cost' => $cost,
                'residual_value' => $residual,
                'useful_life_months' => $life,
                'accumulated_depreciation' => $accumulated,
                'depreciated_through' => $accumulated > 0 || $throughDate ? $throughDate : null,
                'status' => $accumulated >= $cost - $residual ? 'FullyDepreciated' : 'Active',
                'acquisition_type' => $data['acquisition_type'],
                'payment_method' => $opening ? null : $data['payment_method'],
                'cash_account_id' => $opening || $data['payment_method'] !== 'Cash' ? null : $data['cash_account_id'],
                'bank_account_id' => $opening || $data['payment_method'] === 'Cash' ? null : $data['bank_account_id'],
                'created_by' => $request->user()->id,
            ]);
            NumberGenerator::attach($code, $asset);
            FixedAssetLedgerPoster::postAcquisition($asset->load('category'), $request->user()->id);

            return $asset;
        });

        return response()->json($asset->fresh(self::RELATIONS), 201);
    }

    /** Descriptive fields only — cost, life and dates feed the ledger, so they are fixed once posted. */
    public function update(Request $request, FixedAsset $fixedAsset)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string|max:255',
            'serial_no' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:150',
            'custodian_staff_id' => 'nullable|exists:staff,id',
            'invoice_no' => 'nullable|string|max:64',
        ]);
        $fixedAsset->update($data);

        return $fixedAsset->fresh(self::RELATIONS);
    }

    /** Removes a mistaken entry: only before any depreciation or disposal, and the acquisition is reversed in the ledger. */
    public function destroy(Request $request, FixedAsset $fixedAsset)
    {
        if ($fixedAsset->status === 'Disposed' || $fixedAsset->depreciations()->exists() || $fixedAsset->disposal()->exists()) {
            return response()->json(['message' => 'This asset already has depreciation or a disposal posted, so it cannot be deleted. Dispose of it instead.'], 422);
        }
        DB::transaction(function () use ($request, $fixedAsset) {
            $live = FixedAsset::lockForUpdate()->findOrFail($fixedAsset->id);
            if ($live->status === 'Disposed' || $live->depreciations()->exists() || $live->disposal()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['asset' => 'This asset already has depreciation or a disposal posted, so it cannot be deleted.']);
            }
            FixedAssetLedgerPoster::reverseAcquisition($fixedAsset, $request->user()->id);
            $fixedAsset->delete();
        });

        return response()->json(['message' => 'Deleted.']);
    }

    public function dispose(Request $request, FixedAsset $fixedAsset)
    {
        if ($fixedAsset->status === 'Disposed') {
            return response()->json(['message' => 'This asset has already been disposed.'], 422);
        }
        $data = $request->validate(array_merge([
            'disposal_date' => 'required|date|before_or_equal:today',
            'proceeds' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:255',
        ], [
            'payment_method' => 'nullable|in:Cash,Bank,Cheque',
            'cash_account_id' => 'required_if:payment_method,Cash|nullable|exists:cash_accounts,id',
            'bank_account_id' => 'required_if:payment_method,Bank,Cheque|nullable|exists:bank_accounts,id',
        ]));
        if ((float) $data['proceeds'] > 0 && empty($data['payment_method'])) {
            throw ValidationException::withMessages(['payment_method' => 'Choose where the sale proceeds were received.']);
        }
        if ($data['disposal_date'] < $fixedAsset->in_service_date->toDateString()) {
            throw ValidationException::withMessages(['disposal_date' => 'Disposal cannot be before the asset entered service.']);
        }
        // Depreciation must be up to date through the month before disposal, otherwise the gain / loss is wrong.
        $due = FixedAssetDepreciator::firstPeriod($fixedAsset);
        $disposalMonth = \Illuminate\Support\Carbon::parse($data['disposal_date'])->startOfMonth();
        if ($fixedAsset->status === 'Active' && $due->lt($disposalMonth)
            && bccomp(FixedAssetDepreciator::nextAmount($fixedAsset, (string) $fixedAsset->accumulated_depreciation), '0', 2) > 0) {
            return response()->json(['message' => 'Run depreciation up to ' . $disposalMonth->copy()->subMonth()->format('Y-m') . ' before disposing of this asset.'], 422);
        }

        $disposal = DB::transaction(function () use ($fixedAsset, $data, $request) {
            // Lock + re-check: a second disposal (or a depreciation run) landing first must not be overwritten.
            $live = FixedAsset::lockForUpdate()->findOrFail($fixedAsset->id);
            if ($live->status === 'Disposed') {
                throw \Illuminate\Validation\ValidationException::withMessages(['asset' => 'This asset has already been disposed.']);
            }
            $fixedAsset->setRawAttributes($live->getAttributes(), true);
            $disposal = FixedAssetLedgerPoster::postDisposal($fixedAsset->load('category'), $data, $request->user()->id);
            $fixedAsset->update(['status' => 'Disposed']);

            return $disposal;
        });

        return response()->json($disposal, 201);
    }

    /** Shows what the monthly run would post, without posting anything. */
    public function depreciationPreview(Request $request)
    {
        $data = $this->runInput($request);

        return FixedAssetDepreciator::run($data['through'], $request->user()->id, false, $data['branch_id'] ?? null);
    }

    public function depreciationRun(Request $request)
    {
        $data = $this->runInput($request);
        try {
            $result = FixedAssetDepreciator::run($data['through'], $request->user()->id, true, $data['branch_id'] ?? null);
        } catch (FinancePostingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, $result['errors'] && !$result['rows'] ? 422 : 200);
    }

    /** Posted depreciation lines, newest period first — the depreciation history / schedule. */
    public function depreciations(Request $request)
    {
        $q = \App\Models\FixedAssetDepreciation::with('asset:id,code,name,branch_id,asset_category_id')->orderByDesc('period')->orderBy('fixed_asset_id');
        if ($request->filled('period')) {
            $q->where('period', $request->input('period'));
        }
        if ($request->filled('fixed_asset_id')) {
            $q->where('fixed_asset_id', $request->input('fixed_asset_id'));
        }

        return $q->limit(1000)->get();
    }

    private function runInput(Request $request): array
    {
        $data = $request->validate([
            'through' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'branch_id' => 'nullable|exists:branches,id',
        ]);
        if ($data['through'] > now()->format('Y-m')) {
            throw ValidationException::withMessages(['through' => 'Depreciation cannot be run for a future month.']);
        }

        return $data;
    }
}
