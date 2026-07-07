<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collapses the legacy category-type model onto `categories.type` as the
     * single source of truth. The former 'Transfer' type becomes 'Exclude',
     * the denormalized `subcategories.type` and behaviorless
     * `subcategories.is_ignored` columns are dropped, and postings no longer
     * carry the import-time `category_type_code` snapshot.
     */
    public function up(): void
    {
        DB::table('categories')->where('type', 'Transfer')->update(['type' => 'Exclude']);

        Schema::table('subcategories', function (Blueprint $table): void {
            $table->dropIndex(['type', 'name']);
            $table->dropColumn(['type', 'is_ignored']);
        });

        Schema::table('postings', function (Blueprint $table): void {
            $table->dropColumn('category_type_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subcategories', function (Blueprint $table): void {
            $table->string('type')->default('Expense');
            $table->boolean('is_ignored')->default(false);

            $table->index(['type', 'name']);
        });

        Schema::table('postings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('category_type_code')->nullable();
        });

        DB::table('categories')->where('type', 'Exclude')->update(['type' => 'Transfer']);
    }
};
