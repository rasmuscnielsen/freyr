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
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('enable_banking');
            $table->string('status')->default('pending');
            $table->string('aspsp_name');
            $table->char('aspsp_country', 2);
            $table->string('psu_type')->default('personal');
            $table->uuid('authorization_id')->nullable()->unique();
            $table->string('state')->nullable()->unique();
            $table->text('session_id')->nullable();
            $table->string('session_id_hash')->nullable()->unique();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('access_valid_until')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamp('last_failed_sync_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->mediumText('raw_session_payload')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['provider', 'aspsp_country', 'aspsp_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_connections');
    }
};
