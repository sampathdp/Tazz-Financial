<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Loan Inquiry — one search box that finds a customer (name / NIC / phone /
 * code) or any facility code, and lists every facility the customer holds
 * (Loans, Speed Drafts, Pawnings) together, so staff don't have to visit each
 * module separately.
 */
class LoanInquiryController extends Controller
{
    private const TYPES = ['Loan', 'SpeedDraft', 'Pawning'];

    public function index(Request $request)
    {
        $term = trim((string) $request->query('search', ''));
        if (mb_strlen($term) < 2) {
            return ['data' => []];
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        // Customer is branch-scoped, so this respects the user's branch access.
        $customers = Customer::query()
            ->where(function ($q) use ($like) {
                $q->where('full_name', 'like', $like)
                    ->orWhere('nic_no', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhereIn('id', DB::table('credit_facilities')
                        ->whereNull('deleted_at')
                        ->whereIn('facility_type', self::TYPES)
                        ->where('code', 'like', $like)
                        ->select('customer_id'));
            })
            ->orderBy('full_name')
            ->limit(20)
            ->get(['id', 'code', 'full_name', 'nic_no', 'phone']);

        $facilities = DB::table('credit_facilities')
            ->whereIn('customer_id', $customers->pluck('id'))
            ->whereNull('deleted_at')
            ->whereIn('facility_type', self::TYPES)
            ->orderByDesc('id')
            ->get(['id', 'customer_id', 'code', 'facility_type', 'status', 'principal_amount', 'outstanding_balance_cache', 'disbursed_at', 'maturity_date'])
            ->groupBy('customer_id');

        return ['data' => $customers->map(fn ($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'full_name' => $c->full_name,
            'nic_no' => $c->nic_no,
            'phone' => $c->phone,
            'facilities' => ($facilities->get($c->id) ?? collect())->map(fn ($f) => [
                'id' => $f->id,
                'code' => $f->code,
                'type' => $f->facility_type,
                'status' => $f->status,
                'principal_amount' => (float) $f->principal_amount,
                'outstanding_balance' => (float) $f->outstanding_balance_cache,
                'disbursed_at' => $f->disbursed_at,
                'maturity_date' => $f->maturity_date,
            ])->values(),
        ])->values()];
    }
}
