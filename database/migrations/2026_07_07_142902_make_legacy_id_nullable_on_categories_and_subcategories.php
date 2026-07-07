<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Categories and subcategories created through the platform-admin area have
     * no legacy import identifier, so `legacy_id` must be nullable. The unique
     * index is retained; Postgres treats each NULL as distinct so multiple
     * admin-created rows coexist without collisions.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('legacy_id')->nullable()->change();
        });

        Schema::table('subcategories', function (Blueprint $table): void {
            $table->string('legacy_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('legacy_id')->nullable(false)->change();
        });

        Schema::table('subcategories', function (Blueprint $table): void {
            $table->string('legacy_id')->nullable(false)->change();
        });
    }
};
