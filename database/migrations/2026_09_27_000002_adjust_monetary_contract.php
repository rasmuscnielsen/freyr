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
            // Only manual accounts with an explicit opening balance get a
            // calculated total; imported accounts keep their source snapshot.
            $table->decimal('opening_balance', 15, 2)->nullable()->default(null)->change();
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
        });
    }
};
