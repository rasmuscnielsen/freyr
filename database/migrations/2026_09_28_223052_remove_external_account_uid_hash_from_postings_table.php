<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('postings')->where('external_posting_id', '*** hidden ***')
            ->update(['external_posting_id' => null]);

        // The detail-fetch ID is mutable; only the entry reference is durable.
        foreach (DB::table('postings')->where('source', 'enable_banking')->orderBy('id')->lazyById() as $posting) {
            $payload = json_decode($posting->extra_data ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $reference = trim((string) ($payload['entry_reference'] ?? ''));
            $reference = $reference === '' || $reference === '*** hidden ***' || strtolower($reference) === 'null' ? null : $reference;

            if ($reference === null && ($posting->original_description === null || $posting->original_amount === null || $posting->original_currency === null)) {
                throw new RuntimeException("Bank posting {$posting->id} lacks a reference or complete fallback identity.");
            }

            DB::table('postings')->where('id', $posting->id)->update(['external_posting_id' => $reference]);
        }

        DB::statement("CREATE UNIQUE INDEX postings_source_bank_account_external_unique ON postings (user_id, bank_account_id, external_posting_id) WHERE source = 'enable_banking' AND external_posting_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX postings_bank_account_fallback_unique ON postings (user_id, bank_account_id, original_description, date, original_amount, original_currency) WHERE source = 'enable_banking' AND external_posting_id IS NULL");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE postings ADD CONSTRAINT postings_bank_identity_check CHECK (source <> 'enable_banking' OR CASE WHEN external_posting_id IS NULL THEN original_description IS NOT NULL AND BTRIM(original_description) <> '' AND original_amount IS NOT NULL AND original_currency IS NOT NULL AND LENGTH(original_currency) = 3 ELSE BTRIM(external_posting_id) <> '' AND external_posting_id <> '*** hidden ***' AND LOWER(external_posting_id) <> 'null' END)");
        }

        Schema::table('postings', function (Blueprint $table): void {
            $table->dropUnique('postings_source_account_external_unique');
            $table->dropColumn('external_account_uid_hash');
        });
    }

    /**
     * Restore the previous schema; removed hashes and normalized IDs cannot be recovered.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE postings DROP CONSTRAINT postings_bank_identity_check');
        }

        DB::statement('DROP INDEX postings_source_bank_account_external_unique');
        DB::statement('DROP INDEX postings_bank_account_fallback_unique');

        Schema::table('postings', function (Blueprint $table): void {
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
