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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('legacy_id')->unique();
            $table->string('name');
            $table->string('bank_key')->nullable();
            $table->string('bank_name')->nullable();
            $table->char('currency', 3);
            $table->decimal('last_booked_balance', 15, 2)->nullable();
            $table->decimal('last_available_balance', 15, 2)->nullable();
            $table->string('account_type')->nullable();
            $table->string('account_subcategory_key')->nullable();
            $table->string('account_group_legacy_id')->nullable();
            $table->date('ignore_postings_before')->nullable();
            $table->date('ignore_postings_after')->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->date('last_known_posting_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('inactivated_by')->nullable();
            $table->string('account_number')->nullable();
            $table->string('owner_name')->nullable();
            $table->boolean('supports_payment_from')->default(false);
            $table->boolean('supports_payment_to')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->string('connection_type')->nullable();
            $table->unsignedInteger('donation_topup_count')->default(0);
            $table->timestamp('legacy_created_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'bank_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
