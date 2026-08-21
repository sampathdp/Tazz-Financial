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
    /**
     * The GL audit trail — every posting ever made, across every account —
     * is the single highest-write-volume record type in the system (see
     * journal_lines' own migration docblock). No `page` param falls back to
     * the old unbounded shape for any other caller; the Journal Entries
     * browse screen (its only real caller, no export attached) always sends
     * one now.
     */
    public function index(Request $request)
    {
        $query = JournalEntry::with(['lines.account:id,code,name', 'branch:id,name,code', 'poster:id,name'])
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('entry_date', '<=', $d))
            ->when($request->query('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('entry_date')->orderByDesc('id');

        if (!$request->filled('page')) {
            return $query->get();
        }

        return $query->paginate((int) $request->input('perPage', 25));
    }
}
