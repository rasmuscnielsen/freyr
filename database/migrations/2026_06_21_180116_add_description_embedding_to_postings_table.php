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
        $usesPostgres = Schema::getConnection()->getDriverName() === 'pgsql';

        if ($usesPostgres) {
            Schema::ensureVectorExtensionExists();
        }

        Schema::table('postings', function (Blueprint $table) use ($usesPostgres): void {
            $usesPostgres
                ? $table->vector('description_embedding', dimensions: 1536)->nullable()->vectorIndex()
                : $table->json('description_embedding')->nullable();
            $table->timestamp('description_embedded_at')->nullable();

            $table->index(['description_embedded_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->dropColumn(['description_embedding', 'description_embedded_at']);
        });
    }
};
