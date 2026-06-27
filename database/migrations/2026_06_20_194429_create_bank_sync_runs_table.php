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
        Schema::create('bank_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_connection_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('running');
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('new_postings_count')->default(0);
            $table->unsignedInteger('updated_postings_count')->default(0);
            $table->unsignedInteger('duplicate_postings_count')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->mediumText('raw_error')->nullable();
            $table->timestamps();

            $table->index(['bank_connection_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['bank_connection_account_id', 'started_at']);
            $table->index('bank_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_sync_runs');
    }
};
