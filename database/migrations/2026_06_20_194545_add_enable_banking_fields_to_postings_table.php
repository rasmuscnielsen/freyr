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
            $table->string('source')->default('manual');
            $table->string('external_account_uid_hash')->nullable();

            $table->unique(
                ['user_id', 'source', 'external_account_uid_hash', 'external_posting_id'],
                'postings_source_account_external_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->dropUnique('postings_source_account_external_unique');
            $table->dropColumn(['source', 'external_account_uid_hash']);
        });
    }
};
