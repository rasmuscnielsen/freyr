<?php

namespace App\Console\Commands;

use Database\Seeders\DefaultCategoriesSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

#[Signature('app:install
    {--demo : Reset the database and seed demo data}
    {--force : Force the operation to run when in production}')]
#[Description('Set up the application: run migrations and seed the default categories')]
class InstallCommand extends Command
{
    use ConfirmableTrait;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $demo = (bool) $this->option('demo');

        $this->call($demo ? 'migrate:fresh' : 'migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => DefaultCategoriesSeeder::class, '--force' => true]);

        if ($demo) {
            $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

            $this->components->info('Demo environment ready. Log in as john@example.com with password "password".');

            return self::SUCCESS;
        }

        $this->components->info('Freyr is installed. Open the app in your browser to create the first user.');

        return self::SUCCESS;
    }
}
