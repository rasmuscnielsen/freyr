<?php

namespace App\Console\Commands;

use App\Finance\SpiirExportImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spiir:import {path=project/data : Path to a Spiir export directory}')]
#[Description('Import the finance data from a Spiir export directory')]
class ImportSpiirExportCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SpiirExportImporter $importer): int
    {
        $summary = $importer->importExport((string) $this->argument('path'));

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
