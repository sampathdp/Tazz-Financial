<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up to 2026_08_01_000008 (which converted the Cash/Funding/FD
 * module) — found while chasing a Loan payment-allocation bug: the entire
 * Loan module's money-movement tables (facility_schedules, facility_charges,
 * facility_charge_allocations, facility_payment_allocations,
 * facility_waivers), plus journal_lines, loan_types, speed_draft_types,
 * payment_vouchers and bank_reconciliations were still MyISAM. Same root
 * cause and same practical impact as that migration's docblock: every
 * DB::transaction()/DB::rollBack() wrapped around a payment/settlement/
 * waiver write (LoanPaymentAllocator, LoanPaymentController) has been a
 * silent no-op for these tables — a failure partway through a payment
 * allocation leaves whatever already wrote committed, with no rollback.
 * journal_lines being MyISAM is the sharpest version of this: the
 * double-entry ledger's own line items were never atomic with their parent
 * journal_entries row.
 *
 * Deliberately engine-only, unlike 2026_08_01_000008 — that migration also
 * restored each table's missing foreign keys in the same pass, but this
 * environment has real orphaned rows against several of these tables
 * (facility_schedules/facility_charge_allocations/facility_payment_allocations
 * referencing hard-deleted parents, journal_lines referencing missing
 * journal_entries) that would make `ADD CONSTRAINT` fail outright. Deciding
 * whether to delete or reattach that orphaned data — journal_lines
 * especially, since it's ledger data — is a separate call for whoever owns
 * this database, not something to do silently in a migration. This gets the
 * transactional/locking fix in immediately; the FK constraints are a
 * deliberate follow-up once that data is cleaned up.
 *
 * Also re-converts 2026_08_01_000008's own six tables (cash_transfers,
 * fd_interest_payments, fd_renewals, fd_withdrawals, fixed_deposits,
 * funding_products): despite that migration being recorded as run, they were
 * found still on MyISAM here — this database was restored from a dump taken
 * either before that migration ran or from a MyISAM-era backup afterward
 * (exactly the scenario that migration's own docblock warned about).
 * `ALTER TABLE ... ENGINE = InnoDB` is a harmless no-op on a table that's
 * already InnoDB, so listing them again is safe everywhere this runs.
 */
return new class extends Migration
{
    private const TABLES = [
        'facility_schedules', 'facility_charges', 'facility_charge_allocations',
        'facility_payment_allocations', 'facility_waivers',
        'journal_lines', 'loan_types', 'speed_draft_types',
        'payment_vouchers', 'bank_reconciliations',
        'cash_transfers', 'fd_interest_payments', 'fd_renewals',
        'fd_withdrawals', 'fixed_deposits', 'funding_products',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE `{$table}` ENGINE = InnoDB");
        }
    }

    public function down(): void
    {
        // Not reversed — see 2026_08_01_000008's docblock: rolling back would
        // silently recreate the unlocked/non-transactional state this fixes.
    }
};
