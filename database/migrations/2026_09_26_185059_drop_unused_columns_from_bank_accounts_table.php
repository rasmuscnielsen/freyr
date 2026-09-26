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
        Schema::table('bank_accounts', function (Blueprint $table) {
            // Legacy Spiir import metadata that is stored but never read:
            // nothing in the app or the API surface consumes these fields.
            $table->dropColumn([
                'account_subcategory_key',
                'account_group_legacy_id',
                'ignore_postings_after',
                'inactivated_by',
                'owner_name',
                'supports_payment_from',
                'supports_payment_to',
                'connection_type',
                'donation_topup_count',
                'legacy_created_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('account_subcategory_key')->nullable()->after('account_type');
            $table->string('account_group_legacy_id')->nullable()->after('account_subcategory_key');
            $table->date('ignore_postings_after')->nullable()->after('ignore_postings_before');
            $table->string('inactivated_by')->nullable()->after('is_active');
            $table->string('owner_name')->nullable()->after('account_number');
            $table->boolean('supports_payment_from')->default(false)->after('owner_name');
            $table->boolean('supports_payment_to')->default(false)->after('supports_payment_from');
            $table->string('connection_type')->nullable()->after('is_favorite');
            $table->unsignedInteger('donation_topup_count')->default(0)->after('connection_type');
            $table->timestamp('legacy_created_at')->nullable()->after('donation_topup_count');
        });
    }
};
