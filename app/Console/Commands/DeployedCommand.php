<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:deployed')]
#[Description('Run post-deployment tasks: migrations plus cache optimization in production')]
class DeployedCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);

        if (app()->environment('production')) {
            $this->call('optimize');
            $this->call('horizon:terminate');
        }

        return self::SUCCESS;
    }
}
