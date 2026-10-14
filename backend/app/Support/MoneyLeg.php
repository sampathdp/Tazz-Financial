<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;

/**
 * Resolves the physical account a money movement hits for a payment method:
 *   Cash          -> the branch till dedicated to that purpose (CashAccount::branchPrimary)
 *   Bank / Cheque -> the company bank account named on the transaction, when one was chosen
 * Anything else resolves to null (the caller then decides: park receipts in 1030, refuse payouts).
 * Shared by the FD and Savings posters so a bank payment is booked exactly like a cash one.
 */
class MoneyLeg
{
    /** @return array{type: string, id: int, leaf: ChartOfAccount}|null */
    public static function resolve(?string $method, int $branchId, string $cashPurpose, ?int $bankAccountId): ?array
    {
        if ($method === 'Cash') {
            $till = CashAccount::branchPrimary($branchId, $cashPurpose);

            return $till ? ['type' => 'cash', 'id' => (int) $till->id, 'leaf' => ChartOfAccount::forCashTill($till)] : null;
        }
        if (in_array($method, ['Bank', 'Cheque'], true) && $bankAccountId) {
            $bank = BankAccount::find($bankAccountId);

            return $bank ? ['type' => 'bank', 'id' => (int) $bank->id, 'leaf' => ChartOfAccount::forBankAccount($bank)] : null;
        }

        return null;
    }

    /** CASH_IN / CASH_OUT become BANK_DEPOSIT / BANK_WITHDRAWAL when the leg is a bank account. */
    public static function txnType(array $leg, string $cashType): string
    {
        if ($leg['type'] !== 'bank') {
            return $cashType;
        }

        return $cashType === 'CASH_IN' ? 'BANK_DEPOSIT' : 'BANK_WITHDRAWAL';
    }
}
