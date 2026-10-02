<?php

namespace App\Console\Commands;

use App\Services\Spiir\Importer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:spiir:import {path : Path to a Spiir export directory}')]
#[Description('Import the finance data from a Spiir export directory')]
class ImportSpiirExportCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Importer $importer): int
    {
        $path = (string) $this->argument('path');

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = getcwd().DIRECTORY_SEPARATOR.$path;
        }

        if (! is_dir($path)) {
            $this->components->error("The path [{$path}] is not a directory.");

            return self::FAILURE;
        }

        if ($importer->hasDefaultCategories()) {
            $this->components->warn(
                'The seeded default categories will be replaced by the categories from your Spiir export. '
                .'Postings categorized with a default category become uncategorized.',
            );

            if (! $this->confirm('Continue?', true)) {
                return self::FAILURE;
            }
        }

        $summary = $importer->importExport($path);

        $this->components->info(sprintf(
            'Imported %d accounts and %d postings for user #%d. Resolved %d counter entries.',
            $summary['accounts'],
            $summary['postings'],
            $summary['user_id'],
            $summary['counter_entries'],
        ));

        return self::SUCCESS;
    }
}
