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
        Schema::create('postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('categorized_subcategory_id')->nullable()->constrained('subcategories')->nullOnDelete();
            $table->foreignId('counter_entry_posting_id')->nullable()->constrained('postings')->nullOnDelete();
            $table->string('legacy_id')->unique();
            $table->string('counter_entry_legacy_id')->nullable();
            $table->string('split_group_legacy_id')->nullable();
            $table->date('date');
            $table->date('custom_date')->nullable();
            $table->string('description');
            $table->string('original_description')->nullable();
            $table->text('comment')->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('original_amount', 15, 2)->nullable();
            $table->decimal('balance', 15, 2)->nullable();
            $table->string('upload_batch_id')->nullable();
            $table->string('external_posting_id')->nullable();
            $table->string('document_legacy_id')->nullable();
            $table->boolean('is_extraordinary')->default(false);
            $table->boolean('is_account_group_duplicate')->default(false);
            $table->boolean('prevent_marking_as_counter_entry')->default(false);
            $table->boolean('description_changed')->default(false);
            $table->jsonb('extra_data')->nullable();
            $table->unsignedSmallInteger('category_type_code')->nullable();
            $table->unsignedSmallInteger('posting_type')->nullable();
            $table->unsignedSmallInteger('posting_sub_type')->nullable();
            $table->char('currency', 3);
            $table->char('original_currency', 3)->nullable();
            $table->string('merchant_legacy_id')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('state')->nullable();
            $table->unsignedInteger('carbon_emission_in_grams')->nullable();
            $table->string('categorized_by')->nullable();
            $table->timestamp('legacy_created_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date', 'id']);
            $table->index(['bank_account_id', 'date']);
            $table->index('subcategory_id');
            $table->index(['categorized_subcategory_id', 'date']);
            $table->index('counter_entry_posting_id');
            $table->index(['user_id', 'is_extraordinary']);
            $table->index('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('postings');
    }
};
