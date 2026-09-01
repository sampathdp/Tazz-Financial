<?php

namespace App\Listeners;

use App\Events\FinanceRequestSettled;
use App\Models\CashFloatSettlement;
use Illuminate\Support\Facades\Log;

/**
 * When a CashFloat finance request is paid (the branch till funded the
 * float), add it to the settlement period's issued_total and close the
 * request. The money already moved inside FinanceRequestService.
 */
class RecordFloatFundingOnSettled
{
    public function handle(FinanceRequestSettled $event): void
    {
        $request = $event->request;
        if ($request->request_type !== 'CashFloat' || $event->outcome !== 'paid') {
            return;
        }

        $settlementId = ($request->meta ?? [])['float_settlement_id'] ?? null;
        $settlement = $settlementId ? CashFloatSettlement::find($settlementId) : null;
        if (!$settlement) {
            Log::channel('errors')->warning('Float funding paid but no settlement row found', ['finance_request_id' => $request->id]);

            return;
        }

        $settlement->increment('issued_total', (float) $request->amount);
        $request->update(['status' => 'Completed', 'closed_at' => now()]);
    }
}
