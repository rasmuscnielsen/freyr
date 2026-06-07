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
        Schema::create('subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('legacy_id')->unique();
            $table->string('name');
            $table->string('hyphenated_name')->nullable();
            $table->string('expense_type')->nullable();
            $table->string('type');
            $table->text('hints')->nullable();
            $table->boolean('is_ignored')->default(false);
            $table->timestamps();

            $table->index(['category_id', 'name']);
            $table->index(['type', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subcategories');
    }
};
