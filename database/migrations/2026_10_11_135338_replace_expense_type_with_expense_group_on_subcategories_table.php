<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcategories', function (Blueprint $table): void {
            $table->string('expense_group')->nullable();
            $table->dropColumn('expense_type');
        });
    }

    /**
     * Restores the old schema, but cannot recover the discarded classifications.
     */
    public function down(): void
    {
        Schema::table('subcategories', function (Blueprint $table): void {
            $table->string('expense_type')->nullable();
            $table->dropColumn('expense_group');
        });
    }
};
