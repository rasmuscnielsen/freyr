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
        Schema::table('postings', function (Blueprint $table): void {
            $table->dropUnique('postings_source_account_external_unique');
            $table->dropColumn('external_account_uid_hash');
        });

        Schema::table('postings', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'source', 'bank_account_id', 'external_posting_id'],
                'postings_source_bank_account_external_unique',
            );
        });
    }

    /**
     * Restore the previous schema; hashes removed in up() cannot be recovered.
     */
    public function down(): void
    {
        Schema::table('postings', function (Blueprint $table): void {
            $table->dropUnique('postings_source_bank_account_external_unique');
            $table->string('external_account_uid_hash')->nullable();
        });

        Schema::table('postings', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'source', 'external_account_uid_hash', 'external_posting_id'],
                'postings_source_account_external_unique',
            );
        });
    }
};
