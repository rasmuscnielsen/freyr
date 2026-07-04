<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The default category tree is seeded in every environment; the demo
     * user with sample data only in local.
     */
    public function run(): void
    {
        $this->call(DefaultCategoriesSeeder::class);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
