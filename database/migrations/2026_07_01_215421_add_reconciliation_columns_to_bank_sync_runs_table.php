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
        Schema::table('bank_sync_runs', function (Blueprint $table) {
            $table->decimal('expected_balance', 14, 2)->nullable()->after('duplicate_postings_count');
            $table->decimal('computed_balance', 14, 2)->nullable()->after('expected_balance');
            $table->boolean('balance_matches')->nullable()->after('computed_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_sync_runs', function (Blueprint $table) {
            $table->dropColumn(['expected_balance', 'computed_balance', 'balance_matches']);
        });
    }
};
