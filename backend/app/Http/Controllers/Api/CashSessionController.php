<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Support\CashSessionService;
use Illuminate\Http\Request;

/**
 * Daily cash sessions — see App\Support\CashSessionService. A cashier opens
 * the day with a count, closes it with another count + denominations, and a
 * manager verifies the variance.
 */
class CashSessionController extends Controller
{
    private const WITH = ['cashAccount:id,code,name,account_type', 'branch:id,name,code', 'opener:id,name', 'closer:id,name', 'verifier:id,name'];

    public function index(Request $request)
    {
        $q = CashSession::with(self::WITH)->orderByDesc('business_date')->orderByDesc('id');
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($a = $request->query('cash_account_id')) {
            $q->where('cash_account_id', $a);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('business_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('business_date', '<=', $to);
        }

        return $q->paginate((int) $request->query('per_page', 50));
    }

    /** The open session for a cash account (query ?cash_account_id=), or 204. */
    public function current(Request $request)
    {
        $id = (int) $request->query('cash_account_id');
        $session = $id ? CashSessionService::openFor($id) : null;

        return $session ? $session->load(self::WITH) : response()->noContent();
    }

    public function open(Request $request)
    {
        $data = $request->validate([
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'opening_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:255',
        ]);

        return response()->json(
            CashSessionService::open((int) $data['cash_account_id'], (string) $data['opening_amount'], $request->user()->id, $data['notes'] ?? null)->load(self::WITH),
            201
        );
    }

    public function close(Request $request, CashSession $cashSession)
    {
        $data = $request->validate([
            'counted_closing' => 'required|numeric|min:0',
            'denominations' => 'nullable|array',
            'denominations.*' => 'nullable|integer|min:0',
            'coins_amount' => 'nullable|numeric|min:0',
        ]);

        return CashSessionService::close(
            $cashSession, (string) $data['counted_closing'],
            $data['denominations'] ?? [], (string) ($data['coins_amount'] ?? 0), $request->user()->id
        )->load(self::WITH);
    }

    public function verify(Request $request, CashSession $cashSession)
    {
        $data = $request->validate(['post_variance' => 'nullable|boolean']);

        return CashSessionService::verify($cashSession, $request->user()->id, $data['post_variance'] ?? true)->load(self::WITH);
    }
}
