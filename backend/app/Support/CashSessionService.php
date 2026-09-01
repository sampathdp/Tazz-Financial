<?php

namespace App\Support;

use App\Exceptions\FinancePostingException;
use App\Models\CashAccount;
use App\Models\CashSession;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

/**
 * Daily cash session lifecycle: open (a cashier starts the day with a
 * physical count), close (another count + denominations at day's end), and
 * verify (a manager signs off the variance and posts any shortage / excess).
 *
 * expected_closing is the account's current_balance — the same figure every
 * other cash report already trusts, kept honest against the GL by the drift
 * check.
 */
class CashSessionService
{
    public static function open(int $cashAccountId, string $openingCount, int $userId, ?string $notes = null): CashSession
    {
        $account = CashAccount::findOrFail($cashAccountId);
        $today = now()->toDateString();

        if (CashSession::where('cash_account_id', $account->id)->where('status', 'Open')->exists()) {
            throw new FinancePostingException('This cash account already has an open session — close it first.');
        }
        if (CashSession::where('cash_account_id', $account->id)->whereDate('business_date', $today)->exists()) {
            throw new FinancePostingException("A session for {$account->name} on {$today} already exists.");
        }

        return CashSession::create([
            'cash_account_id' => $account->id,
            'branch_id' => $account->branch_id,
            'business_date' => $today,
            'opening_amount' => $openingCount,
            'opening_expected' => $account->current_balance,
            'status' => 'Open',
            'notes' => $notes,
            'opened_by' => $userId,
            'opened_at' => now(),
        ]);
    }

    /**
     * @param array<int|string,int> $denominations note value => quantity
     */
    public static function close(CashSession $session, string $countedClosing, array $denominations, string $coins, int $userId): CashSession
    {
        if ($session->status !== 'Open') {
            throw new FinancePostingException('Only an open session can be closed.');
        }
        $expected = (string) CashAccount::findOrFail($session->cash_account_id)->current_balance;
        $variance = bcsub($countedClosing, $expected, 2);

        $session->update([
            'expected_closing' => $expected,
            'counted_closing' => $countedClosing,
            'variance' => $variance,
            'shortage' => bccomp($variance, '0.00', 2) < 0 ? bcsub('0.00', $variance, 2) : '0.00',
            'excess' => bccomp($variance, '0.00', 2) > 0 ? $variance : '0.00',
            'denominations' => $denominations,
            'coins_amount' => $coins,
            'status' => 'Closed',
            'closed_by' => $userId,
            'closed_at' => now(),
        ]);

        return $session->fresh();
    }

    /**
     * A manager signs off. If postVariance and the count differed, a shortage
     * is posted to 5310 / an excess to 4905 and the account's balance is
     * trued to the counted figure — same treatment as a float settlement.
     * The person who closed the session cannot verify it.
     */
    public static function verify(CashSession $session, int $userId, bool $postVariance = true): CashSession
    {
        if ($session->status !== 'Closed') {
            throw new FinancePostingException('Only a closed session can be verified.');
        }
        if ((int) $session->closed_by === $userId) {
            throw new FinancePostingException('The person who closed a session cannot verify it.');
        }

        return DB::transaction(function () use ($session, $userId, $postVariance) {
            $shortage = (string) $session->shortage;
            $excess = (string) $session->excess;

            if ($postVariance && (bccomp($shortage, '0.00', 2) > 0 || bccomp($excess, '0.00', 2) > 0)) {
                $account = CashAccount::lockForUpdate()->findOrFail($session->cash_account_id);
                $leaf = ChartOfAccount::forCashTill($account);
                if (bccomp($shortage, '0.00', 2) > 0) {
                    FinancePostingService::execute([
                        'txn_type' => 'ADJUSTMENT', 'amount' => $shortage, 'entry_date' => now()->toDateString(),
                        'branch_id' => $account->branch_id,
                        'movements' => [['account_type' => 'cash', 'account_id' => $account->id, 'delta' => '-' . $shortage]],
                        'gl_lines' => [
                            ['account_id' => ChartOfAccount::system('5310')->id, 'debit' => $shortage, 'credit' => 0],
                            ['account_id' => $leaf->id, 'debit' => 0, 'credit' => $shortage],
                        ],
                        'description' => "Cash session shortage - {$account->name} {$session->business_date->toDateString()}",
                        'source_module' => 'CashSession', 'source_id' => $session->id, 'executed_by' => $userId,
                    ]);
                } else {
                    FinancePostingService::execute([
                        'txn_type' => 'ADJUSTMENT', 'amount' => $excess, 'entry_date' => now()->toDateString(),
                        'branch_id' => $account->branch_id,
                        'movements' => [['account_type' => 'cash', 'account_id' => $account->id, 'delta' => $excess]],
                        'gl_lines' => [
                            ['account_id' => $leaf->id, 'debit' => $excess, 'credit' => 0],
                            ['account_id' => ChartOfAccount::system('4905')->id, 'debit' => 0, 'credit' => $excess],
                        ],
                        'description' => "Cash session overage - {$account->name} {$session->business_date->toDateString()}",
                        'source_module' => 'CashSession', 'source_id' => $session->id, 'executed_by' => $userId,
                    ]);
                }
                CashAccount::where('id', $account->id)->update(['last_reconciled_at' => now()]);
            }

            $session->update(['status' => 'Verified', 'verified_by' => $userId, 'verified_at' => now()]);

            return $session->fresh();
        });
    }

    /** The open session for a cash account today, or null. */
    public static function openFor(int $cashAccountId): ?CashSession
    {
        return CashSession::where('cash_account_id', $cashAccountId)->where('status', 'Open')->first();
    }
}
