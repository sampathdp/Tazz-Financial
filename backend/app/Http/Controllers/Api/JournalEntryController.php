<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use Illuminate\Http\Request;

/**
 * Read-only browse of the general ledger's audit trail — every posting
 * LedgerPoster has made, across every account, newest first. There is no
 * store()/create endpoint: journal entries are always a side effect of a
 * business event (a payment, an expense voucher, ...) posted by LedgerPoster,
 * never authored directly — see the Chart of Accounts guide's reasoning for
 * why a single writer matters here.
 */
class JournalEntryController extends Controller
{
    public function index(Request $request)
    {
        return JournalEntry::with(['lines.account:id,code,name', 'branch:id,name,code', 'poster:id,name'])
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('entry_date', '<=', $d))
            ->when($request->query('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->get();
    }
}
