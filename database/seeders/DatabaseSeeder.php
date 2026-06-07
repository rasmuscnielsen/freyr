<?php

namespace Database\Seeders;

use App\Finance\SpiirExportImporter;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $importer = app(SpiirExportImporter::class);

        $importer->importExport(base_path('project/data'));
    }
}
