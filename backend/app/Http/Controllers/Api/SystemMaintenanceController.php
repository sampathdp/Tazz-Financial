<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Admin-only "start fresh" maintenance action.
 *
 * cleanTransactions() truncates every operational / transactional table
 * (loans, facilities, payments, ledger, deposits, pawning, expenses,
 * petty cash, approvals, number sequences, ...) and deletes the uploaded
 * files that belonged to them, while KEEPING all master / configuration
 * data: customers (and their photos/documents), users, roles, permissions,
 * branches, company profile, every *_types table, funding products,
 * vendors, banks, expense/document/charge categories, chart of accounts,
 * approval types and pawning gold rates.
 *
 * Guardrails (see routes/api.php + the checks below):
 *   - 'admin' role only
 *   - blocked when APP_ENV=production unless DB_CLEAN_ENABLED=true
 *   - caller must re-enter their password
 *   - caller must type the confirmation phrase exactly
 */
class SystemMaintenanceController extends Controller
{
    /** Typed verbatim in the UI to arm the action. */
    public const CONFIRM_PHRASE = 'DELETE ALL TRANSACTION DATA';

    /**
     * Operational tables, wiped in full. Order does not matter — foreign key
     * checks are disabled for the duration of the truncate loop.
     */
    private const WIPE_TABLES = [
        // --- Credit facilities: loans, speed drafts, pawning (one base table) ---
        'loan_facility_details',
        'speed_draft_facility_details',
        'pawning_facility_details',
        'facility_schedules',
        'facility_payments',
        'facility_payment_allocations',
        'facility_charges',
        'facility_charge_allocations',
        'facility_collateral',
        'facility_collateral_documents',
        'facility_collateral_vehicle_details',
        'facility_collateral_jewelry_details',
        'facility_collateral_pawn_details',
        'facility_co_applicants',
        'facility_diary_notes',
        'facility_documents',
        'facility_guarantors',
        'facility_insurance_details',
        'facility_post_dated_cheques',
        'facility_reschedules',
        'facility_status_events',
        'facility_waivers',
        'loan_provisions',
        'sales_executive_commissions',
        'speed_draft_renewals',
        'credit_facilities',

        // --- Pawning auctions / redemptions ---
        'pawning_auction_items',
        'pawning_auctions',
        'pawning_redemption_items',
        'pawning_redemptions',
        'pawning_renewals',

        // --- Funding: deposits & fixed deposits ---
        'fd_interest_payments',
        'fd_part_payments',
        'fd_renewals',
        'fd_withdrawals',
        'fd_deposits',
        'fixed_deposits',

        // --- Accounting ledger ---
        'journal_lines',
        'journal_entries',

        // --- Bank ---
        'bank_transactions',
        'bank_reconciliations',

        // --- Cashbook ---
        'cashbook_entries',
        'cash_transfers',
        'cash_reconciliations',

        // --- Expenses & petty cash ---
        'payment_vouchers',
        'expenses',
        'petty_cash_vouchers',
        'petty_cash_replenishments',
        'petty_cash_funds',

        // --- Approvals (the registry of approval *types* is kept) ---
        'approval_requests',

        // --- Subscriptions / document expiry reminders ---
        'subscriptions',

        // --- Generated document numbers (so numbering restarts from 1) ---
        'document_number_log',
        'number_sequences',
    ];

    /** Uploaded-file folders on the 'public' disk that belong to wiped rows. */
    private const WIPE_STORAGE_DIRS = ['loans', 'speed-drafts'];

    public function cleanTransactions(Request $request)
    {
        abort_unless(
            ! app()->isProduction() || config('app.db_clean_enabled', false),
            403,
            'Data cleaning is disabled in production. Set DB_CLEAN_ENABLED=true to allow it.'
        );

        $data = $request->validate([
            'password' => 'required|string',
            'confirm_phrase' => 'required|string',
        ]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            return response()->json(['message' => 'Incorrect password.'], 422);
        }
        if ($data['confirm_phrase'] !== self::CONFIRM_PHRASE) {
            return response()->json(['message' => 'Confirmation phrase does not match.'], 422);
        }

        // Not wrapped in a transaction: MySQL's TRUNCATE forces an implicit
        // commit, so a mid-loop failure cannot roll back. The table list is
        // fixed and FK checks are off, so a partial wipe is not a real risk;
        // re-running the action finishes any interrupted clean.
        $wiped = [];
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::WIPE_TABLES as $table) {
                $wiped[$table] = DB::table($table)->count();
                DB::table($table)->truncate();
            }
            // Account definitions are kept; their running balance is reset
            // to the opening balance since every movement is now gone.
            DB::statement('UPDATE bank_accounts SET current_balance = opening_balance');
            DB::statement('UPDATE cash_accounts SET current_balance = opening_balance');
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $filesDeleted = 0;
        foreach (self::WIPE_STORAGE_DIRS as $dir) {
            $filesDeleted += count(Storage::disk('public')->allFiles($dir));
            Storage::disk('public')->deleteDirectory($dir);
        }

        return [
            'ok' => true,
            'rowsDeleted' => array_sum($wiped),
            'tablesWiped' => count(array_filter($wiped)),
            'filesDeleted' => $filesDeleted,
        ];
    }
}
