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
        Schema::table('bank_connection_accounts', function (Blueprint $table) {
            $table->boolean('provides_running_balance')->nullable()->after('last_transaction_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_connection_accounts', function (Blueprint $table) {
            $table->dropColumn('provides_running_balance');
        });
    }
};
