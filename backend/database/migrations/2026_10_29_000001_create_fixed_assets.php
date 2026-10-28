<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Assets module: categories (each mapped to an asset / accumulated-depreciation / expense account),
 * the asset register, monthly straight-line depreciation rows and disposals.
 *
 * Chart of accounts: 1300 Fixed Assets becomes a header over per-category cost accounts (1310-1350),
 * 1390 Accumulated Depreciation is its contra account, 5450 Depreciation Expense, 4940 / 5460 gain / loss on disposal.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        // code, name, type, normal balance, parent code
        ['1310', 'Land & Buildings', 'Asset', 'Debit', '1300'],
        ['1320', 'Furniture & Fittings', 'Asset', 'Debit', '1300'],
        ['1330', 'Office Equipment', 'Asset', 'Debit', '1300'],
        ['1340', 'Computers & IT Equipment', 'Asset', 'Debit', '1300'],
        ['1350', 'Motor Vehicles', 'Asset', 'Debit', '1300'],
        ['1390', 'Accumulated Depreciation', 'Asset', 'Credit', null], // contra-asset
        ['4940', 'Gain on Disposal of Fixed Assets', 'Income', 'Credit', '4900'],
        ['5450', 'Depreciation Expense', 'Expense', 'Debit', null],
        ['5460', 'Loss on Disposal of Fixed Assets', 'Expense', 'Debit', null],
    ];

    /** name, asset account code, useful life months, residual % */
    private const CATEGORIES = [
        ['Land & Buildings', '1310', 480, 0],
        ['Furniture & Fittings', '1320', 120, 0],
        ['Office Equipment', '1330', 60, 0],
        ['Computers & IT Equipment', '1340', 48, 0],
        ['Motor Vehicles', '1350', 60, 10],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::ACCOUNTS as [$code, $name, $type, $normal, $parentCode]) {
            if (DB::table('chart_of_accounts')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('chart_of_accounts')->insert([
                'code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $normal,
                'parent_id' => $parentCode ? DB::table('chart_of_accounts')->where('code', $parentCode)->value('id') : null,
                'is_system' => true, 'is_postable' => true, 'status' => 'Active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        // 1300 now only groups the per-category cost accounts.
        DB::table('chart_of_accounts')->where('code', '1300')->update(['is_postable' => false]);

        foreach (['journal_entries', 'document_number_log'] as $table) {
            $this->addEnumValues($table, 'source_type', ['FixedAsset', 'FixedAssetDepreciation', 'FixedAssetDisposal']);
        }
        $this->addEnumValues('number_sequences', 'module', ['fixed_asset']);

        Schema::create('asset_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->foreignId('asset_account_id')->constrained('chart_of_accounts');
            $t->foreignId('accumulated_depreciation_account_id')->constrained('chart_of_accounts');
            $t->foreignId('expense_account_id')->constrained('chart_of_accounts');
            $t->unsignedSmallInteger('default_useful_life_months')->default(60);
            $t->decimal('default_residual_percent', 5, 2)->default(0);
            $t->enum('status', ['Active', 'Inactive'])->default('Active');
            $t->timestamps();
        });

        Schema::create('fixed_assets', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 150);
            $t->foreignId('asset_category_id')->constrained('asset_categories');
            $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $t->string('description', 255)->nullable();
            $t->string('serial_no', 100)->nullable();
            $t->string('location', 150)->nullable();
            $t->foreignId('custodian_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->string('invoice_no', 64)->nullable();
            $t->date('purchase_date');
            $t->date('in_service_date');
            $t->decimal('cost', 14, 2);
            $t->decimal('residual_value', 14, 2)->default(0);
            $t->unsignedSmallInteger('useful_life_months');
            $t->enum('depreciation_method', ['straight_line'])->default('straight_line');
            $t->decimal('accumulated_depreciation', 14, 2)->default(0);
            $t->date('depreciated_through')->nullable(); // month-end of the last depreciated period
            $t->enum('status', ['Active', 'FullyDepreciated', 'Disposed'])->default('Active');
            // A purchase moves money; Opening is an asset already owned when the system started.
            $t->enum('acquisition_type', ['Purchase', 'Opening'])->default('Purchase');
            $t->string('payment_method', 16)->nullable();
            $t->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $t->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->foreignId('finance_transaction_id')->nullable()->constrained('finance_transactions')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'branch_id']);
        });

        Schema::create('fixed_asset_depreciations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $t->char('period', 7); // YYYY-MM
            $t->date('period_end');
            $t->decimal('amount', 14, 2);
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['fixed_asset_id', 'period']);
            $t->index('period');
        });

        Schema::create('fixed_asset_disposals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fixed_asset_id')->unique()->constrained('fixed_assets')->cascadeOnDelete();
            $t->date('disposal_date');
            $t->decimal('proceeds', 14, 2)->default(0);
            $t->string('payment_method', 16)->nullable();
            $t->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $t->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $t->decimal('book_value', 14, 2);
            $t->decimal('gain_loss', 14, 2); // positive = gain, negative = loss
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->foreignId('finance_transaction_id')->nullable()->constrained('finance_transactions')->nullOnDelete();
            $t->string('notes', 255)->nullable();
            $t->foreignId('disposed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        foreach (self::CATEGORIES as [$name, $assetCode, $life, $residual]) {
            DB::table('asset_categories')->insert([
                'name' => $name,
                'asset_account_id' => DB::table('chart_of_accounts')->where('code', $assetCode)->value('id'),
                'accumulated_depreciation_account_id' => DB::table('chart_of_accounts')->where('code', '1390')->value('id'),
                'expense_account_id' => DB::table('chart_of_accounts')->where('code', '5450')->value('id'),
                'default_useful_life_months' => $life,
                'default_residual_percent' => $residual,
                'status' => 'Active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_disposals');
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('asset_categories');
        // Chart accounts and enum values are left in place: journal lines may already reference them.
    }

    private function addEnumValues(string $table, string $column, array $values): void
    {
        $type = DB::selectOne("SHOW COLUMNS FROM `{$table}` WHERE Field = '{$column}'")->Type;
        $missing = array_filter($values, fn ($v) => !str_contains($type, "'{$v}'"));
        if (!$missing) {
            return;
        }
        $add = implode('', array_map(fn ($v) => ",'{$v}'", $missing));
        $nullable = DB::selectOne("SHOW COLUMNS FROM `{$table}` WHERE Field = '{$column}'")->Null === 'YES' ? 'DEFAULT NULL' : 'NOT NULL';
        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` " . substr($type, 0, -1) . "{$add}) {$nullable}");
    }
};
