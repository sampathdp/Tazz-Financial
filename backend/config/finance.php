<?php

return [
    /*
     | When true (the default), a module that needs a dedicated till (collections, document charges, FD, ...)
     | gets exactly that till or a clear error, never another one. Set FINANCE_STRICT_TILL_PURPOSES=false only
     | for the older test suite, which models a branch as one till that serves every purpose.
     */
    'strict_till_purposes' => env('FINANCE_STRICT_TILL_PURPOSES', true),
];
