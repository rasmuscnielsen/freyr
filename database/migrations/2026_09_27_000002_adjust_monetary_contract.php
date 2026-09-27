<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            // Reporting amount: null while a foreign-currency conversion is
            // pending. Null is not zero.
            $table->decimal('amount', 15, 2)->nullable()->change();
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            // Null marks an opening balance that is unknown rather than zero,
            // e.g. an imported foreign-currency account whose source balance
            // cannot anchor the chain.
            $table->decimal('opening_balance', 15, 2)->nullable()->default(0)->change();
            // Where the opening balance came from: 'derived' means it was
            // computed from the bank's booked balance, so a matching
            // reconciliation is true by construction and not independent
            // verification.
            $table->string('opening_balance_origin')->nullable()->after('opening_balance');
        });

        Schema::table('bank_sync_runs', function (Blueprint $table) {
            $table->string('balance_match_basis')->nullable()->after('balance_matches');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->nullable(false)->change();
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->decimal('opening_balance', 15, 2)->nullable(false)->default(0)->change();
            $table->dropColumn('opening_balance_origin');
        });

        Schema::table('bank_sync_runs', function (Blueprint $table) {
            $table->dropColumn('balance_match_basis');
        });
    }
};
