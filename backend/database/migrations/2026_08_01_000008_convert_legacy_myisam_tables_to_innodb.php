<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Found while wiring cash_till_links' FK to cash_accounts (adding a foreign
 * key against a MyISAM table fails outright — that's how this surfaced):
 * twelve tables — the entire Cash and Funding/FD modules, plus the number
 * generator — are running on MyISAM with a *complete absence* of enforced
 * foreign keys, despite every one of them being declared with ->constrained()
 * in their original migrations. Most likely cause: this database was restored
 * from a dump taken before those constraints existed, on a MySQL instance
 * whose default engine was MyISAM at the time.
 *
 * The practical impact is worse than "no referential integrity": MyISAM has
 * no row-level locking or transactions, so every `lockForUpdate()` in
 * CashbookPoster and NumberGenerator — both written assuming InnoDB — is
 * silently a no-op today. Concurrent cash receipts can lose an update to
 * `cash_accounts.current_balance`, and concurrent NumberGenerator::next()
 * calls can hand out the same receipt/loan/FD number twice. Converting the
 * engine and restoring the missing constraints fixes both at once.
 *
 * Verified against production-shaped local data (fixed_deposits, fd_deposits,
 * fd_interest_payments, fd_renewals, cashbook_entries, cash_transfers, ...)
 * with no orphaned foreign key values before writing this — every ADD
 * CONSTRAINT below is expected to succeed cleanly. `number_sequences` and
 * `funding_products` get the engine change only: neither ever declared FKs
 * (number_sequences' branch_id/loan_type_id/funding_product_id are
 * deliberately unconstrained "0 = not part of this key" columns — see that
 * migration's docblock).
 */
return new class extends Migration
{
    private const TABLES = [
        'cash_accounts', 'cashbook_entries', 'cash_transfers', 'cash_reconciliations',
        'fixed_deposits', 'fd_deposits', 'fd_interest_payments', 'fd_renewals', 'fd_withdrawals',
        'funding_products', 'number_sequences', 'generated_numbers',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE `{$table}` ENGINE = InnoDB");
        }

        Schema::table('cash_accounts', function ($table) {
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
        });

        Schema::table('cashbook_entries', function ($table) {
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('cash_account_id')->references('id')->on('cash_accounts')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('cash_transfers', function ($table) {
            $table->foreign('from_cash_account_id')->references('id')->on('cash_accounts')->restrictOnDelete();
            $table->foreign('to_cash_account_id')->references('id')->on('cash_accounts')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('cash_reconciliations', function ($table) {
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('reconciled_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('fixed_deposits', function ($table) {
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('funding_product_id')->references('id')->on('funding_products')->restrictOnDelete();
            $table->foreign('renewed_from_id')->references('id')->on('fixed_deposits')->nullOnDelete();
            $table->foreign('renewed_to_id')->references('id')->on('fixed_deposits')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('fd_deposits', function ($table) {
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('fixed_deposit_id')->references('id')->on('fixed_deposits')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('fd_interest_payments', function ($table) {
            $table->foreign('fixed_deposit_id')->references('id')->on('fixed_deposits')->cascadeOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('fd_renewals', function ($table) {
            $table->foreign('old_fixed_deposit_id')->references('id')->on('fixed_deposits')->restrictOnDelete();
            $table->foreign('new_fixed_deposit_id')->references('id')->on('fixed_deposits')->restrictOnDelete();
            $table->foreign('additional_deposit_id')->references('id')->on('fd_deposits')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('fd_withdrawals', function ($table) {
            $table->foreign('fixed_deposit_id')->references('id')->on('fixed_deposits')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('generated_numbers', function ($table) {
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('loan_type_id')->references('id')->on('loan_types')->nullOnDelete();
            $table->foreign('funding_product_id')->references('id')->on('funding_products')->nullOnDelete();
            $table->foreign('generated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Not reversed: the point of this migration is that these constraints
        // should have existed all along. Rolling back would silently recreate
        // the unlocked/unenforced state this fixes.
    }
};
