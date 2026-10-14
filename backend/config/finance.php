<?php

return [
    /*
     | When true (the default), a module that needs a dedicated till (collections, document charges, FD, ...)
     | gets exactly that till or a clear error, never another one. Set FINANCE_STRICT_TILL_PURPOSES=false only
     | for the older test suite, which models a branch as one till that serves every purpose.
     */
    'strict_till_purposes' => env('FINANCE_STRICT_TILL_PURPOSES', true),

    /*
     | When true (the default), a loan is paid out only from a Head Office bank account (method Bank or Cheque);
     | there is no cash disbursement and no per-branch Disbursements till. Another branch's loan paid from Head
     | Office is settled through the inter-branch accounts automatically. Set FINANCE_DISBURSE_FROM_HO_BANK=false
     | only for the older test suite, which pays loans out of a cash till.
     */
    'disburse_from_head_office_bank' => env('FINANCE_DISBURSE_FROM_HO_BANK', true),
];
