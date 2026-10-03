<?php

namespace App\Providers;

use App\Services\EnableBanking\Client;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(PlatformSettings::class);

        $this->app->bind(Client::class, fn (): Client => new Client(
            $this->app->make(PlatformSettings::class)->enableBankingConfig(),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Passport::tokensCan([
            'read' => 'Read your budgeting data',
            'write' => 'Modify your budgeting data',
        ]);

        // Passport 13 ships no built-in consent screen — render it with Inertia.
        Passport::authorizationView(
            fn (array $parameters) => Inertia::render('auth/authorize', [
                'client' => ['name' => $parameters['client']->name],
                'scopes' => collect($parameters['scopes'])
                    ->map(fn (Scope $scope) => $scope->toArray())
                    ->all(),
                'authToken' => $parameters['authToken'],
                'csrfToken' => $parameters['request']->session()->token(),
            ]),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
