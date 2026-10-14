<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DocStore;
use App\Support\TransactionalDataCleaner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Admin-only "start fresh" maintenance action.
 *
 * cleanTransactions() truncates every table except the master/configuration set
 * in TransactionalDataCleaner::KEEP (loans, facilities, payments, ledger, cash sessions,
 * finance transactions/requests, deposits, pawning, expenses, petty cash, approvals,
 * number sequences, ...) and deletes the uploaded
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

    /** Uploaded-file folders on the 'public' disk that belong to wiped rows. */
    private const WIPE_STORAGE_DIRS = ['loans', 'speed-drafts'];  // customers/ is kept with the customers table

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

        // Not wrapped in a transaction: MySQL's TRUNCATE forces an implicit commit, so a
        // mid-loop failure cannot roll back. Re-running the action finishes an interrupted clean.
        // Which tables go is decided by TransactionalDataCleaner (keep-list of master data).
        $wiped = TransactionalDataCleaner::wipe();

        $filesDeleted = 0;
        foreach (self::WIPE_STORAGE_DIRS as $dir) {
            $filesDeleted += DocStore::deleteDirectory($dir);
        }

        return [
            'ok' => true,
            'rowsDeleted' => array_sum($wiped),
            'tablesWiped' => count(array_filter($wiped)),
            'filesDeleted' => $filesDeleted,
        ];
    }
}
