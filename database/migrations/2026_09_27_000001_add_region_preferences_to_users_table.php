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
        Schema::table('users', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable()->after('is_platform_admin');
            $table->string('timezone', 64)->nullable()->after('country_code');
            $table->char('reporting_currency', 3)->nullable()->after('timezone');
            $table->timestamp('region_completed_at')->nullable()->after('reporting_currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['country_code', 'timezone', 'reporting_currency', 'region_completed_at']);
        });
    }
};
