<?php

namespace App\Console\Commands;

use Database\Seeders\DefaultCategoriesSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:install {--demo : Reset the database and seed demo data (local only)}')]
#[Description('Set up the application: run migrations and seed the default categories')]
class InstallCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('demo')) {
            return $this->installDemo();
        }

        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => DefaultCategoriesSeeder::class, '--force' => true]);

        $this->components->info('Freyr is installed. Visit /register to create your account.');

        return self::SUCCESS;
    }

    private function installDemo(): int
    {
        if (! app()->environment('local')) {
            $this->components->error('The --demo option is only available in the local environment.');

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => DefaultCategoriesSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $this->components->info('Demo environment ready. Log in as john@example.com with password "password".');

        return self::SUCCESS;
    }
}
