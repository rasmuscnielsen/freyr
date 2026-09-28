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
        // Shared, non-personal rate cache: one row per provider / base / quote
        // / effective date, reused across all users and workers.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            // The provider's actual observation date — a requested weekend
            // date resolves to the preceding publication date, not the date
            // asked for.
            $table->date('effective_date');
            $table->decimal('rate', 24, 10);
            $table->timestamps();

            $table->unique(
                ['provider', 'base_currency', 'quote_currency', 'effective_date'],
                'exchange_rates_lookup_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
