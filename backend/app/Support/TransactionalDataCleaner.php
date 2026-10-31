<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one definition of "start fresh": every table is wiped EXCEPT the setup/master
 * data listed in KEEP. Working from a keep list (not a hand-maintained wipe list)
 * means a table added later is cleaned by default - leftovers such as cash_sessions
 * or finance_transactions otherwise survive a clean and then collide with the
 * restarted number sequences.
 *
 * Used by SystemMaintenanceController (the Maintenance screen) and `php artisan data:clean`.
 */
class TransactionalDataCleaner
{
    /** Setup / master data that always survives a clean. */
    public const KEEP = [
        'migrations',
        'users', 'roles', 'permissions', 'role_permissions', 'user_permission_overrides',
        'personal_access_tokens', 'password_reset_tokens',
        'branches', 'company_profile',
        'approval_types', 'approval_type_approvers', 'approval_tiers',
        'loan_types', 'funding_products', 'savings_products', 'speed_draft_types',
        'pawning_types', 'pawning_article_types', 'pawning_gold_rates',
        'vendors', 'banks', 'bank_accounts', 'cash_accounts', 'chart_of_accounts',
        'expense_categories', 'document_categories', 'charge_categories',
        'finance_settings',
        // Setup and people data that were being wiped by mistake: fixed-asset categories (the seeded setup the
        // Fixed Assets screen needs), the FD-loan policy, staff (each login is linked to a staff row) and the
        // users' own profile data and subscriptions list.
        'asset_categories', 'fd_loan_policies', 'staff', 'user_achievements', 'subscriptions',
        // Customers are master data too (see SystemMaintenanceController) unless asked otherwise.
        'customers', 'customer_business_details', 'customer_employment_details', 'customer_documents', 'customer_bank_details',
    ];

    public const CUSTOMER_TABLES = ['customers', 'customer_business_details', 'customer_employment_details', 'customer_documents', 'customer_bank_details'];

    /** @return string[] tables that a clean would wipe */
    public static function tables(bool $includeCustomers = false): array
    {
        $keep = $includeCustomers ? array_diff(self::KEEP, self::CUSTOMER_TABLES) : self::KEEP;

        return collect(DB::select('select table_name as t from information_schema.tables where table_schema = ? and table_type = "BASE TABLE"', [DB::getDatabaseName()]))
            ->pluck('t')->reject(fn ($t) => in_array($t, $keep, true))->sort()->values()->all();
    }

    /** @return array<string,int> table => rows that were deleted */
    public static function wipe(bool $includeCustomers = false): array
    {
        $wiped = [];
        Schema::disableForeignKeyConstraints();
        try {
            foreach (self::tables($includeCustomers) as $table) {
                $wiped[$table] = DB::table($table)->count();
                DB::table($table)->truncate();
            }
            // Account definitions are kept; every movement is gone, so balances go back to opening.
            DB::statement('UPDATE bank_accounts SET current_balance = opening_balance');
            DB::statement('UPDATE cash_accounts SET current_balance = opening_balance');
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $wiped;
    }
}
