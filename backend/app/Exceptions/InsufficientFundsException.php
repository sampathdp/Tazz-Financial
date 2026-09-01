<?php

namespace App\Exceptions;

/**
 * A cash/bank account does not have enough available balance for an outbound
 * finance transaction, and the account is not flagged allow_negative (cash)
 * / within its overdraft_limit (bank). See FinancePostingService.
 */
class InsufficientFundsException extends FinancePostingException
{
}
