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
        Schema::create('bank_connection_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('unlinked');
            $table->text('external_account_uid');
            $table->string('external_account_uid_hash')->index();
            $table->text('identification_hash')->nullable();
            $table->mediumText('identification_hashes')->nullable();
            $table->string('name');
            $table->string('details')->nullable();
            $table->char('currency', 3)->default('DKK');
            $table->text('iban')->nullable();
            $table->text('account_number')->nullable();
            $table->string('account_number_last_four', 4)->nullable();
            $table->string('bic')->nullable();
            $table->string('product')->nullable();
            $table->string('cash_account_type')->nullable();
            $table->mediumText('raw_account_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->date('last_transaction_date')->nullable();
            $table->timestamps();

            $table->unique(['bank_connection_id', 'external_account_uid_hash'], 'connection_account_uid_unique');
            $table->index(['user_id', 'status']);
            $table->index(['bank_account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_connection_accounts');
    }
};
