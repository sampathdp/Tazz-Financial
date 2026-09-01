<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinanceAuditEvent;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;

/**
 * Read-only view of the finance audit trail (finance_audit_events). Backs the
 * Audit Log screen and the Transaction Audit report. Branch-scoped.
 */
class FinanceAuditController extends Controller
{
    public function index(Request $request)
    {
        $q = CurrentBranch::applyScope(FinanceAuditEvent::with('actor:id,name'))->orderByDesc('id');

        if ($a = $request->query('action')) {
            $q->where('action', 'like', $a . '%');
        }
        if ($st = $request->query('subject_type')) {
            $q->where('subject_type', $st);
        }
        if ($sid = $request->query('subject_id')) {
            $q->where('subject_id', $sid);
        }
        if ($actor = $request->query('actor_id')) {
            $q->where('actor_id', $actor);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        return $q->paginate((int) $request->query('per_page', 60));
    }
}
