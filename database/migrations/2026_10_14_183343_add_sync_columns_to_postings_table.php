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
        // Back the API's `filter[changed_since]` incremental sync: deletes are
        // soft so they can be reported, and stamp `updated_at` as well.
        Schema::table('postings', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['user_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'updated_at']);
            $table->dropSoftDeletes();
        });
    }
};
