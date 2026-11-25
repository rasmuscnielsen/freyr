<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Passport::actingAs() builds a CryptKey from the signing keys, which are
    // gitignored — generate them once per run so tests work from a clean
    // checkout.
    ->beforeEach(fn () => providePassportKeys())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Ensure the OAuth signing keys exist — they live in storage/ and are
 * gitignored, so a clean checkout has none. No-ops when already generated.
 */
function providePassportKeys(): void
{
    if (! file_exists(storage_path('oauth-private.key'))) {
        Artisan::call('passport:keys');
    }
}

/**
 * Provision OAuth signing keys and a personal access client so that
 * `User::createToken()` (Passport) can mint real tokens in tests.
 */
function providePersonalAccessClient(): void
{
    providePassportKeys();

    app(ClientRepository::class)
        ->createPersonalAccessGrantClient('Personal access', 'users');
}

/**
 * Answer OpenAI embedding requests, such as the check that runs before an
 * API key is saved, with a working response or the given error status.
 */
function fakeOpenAiEmbeddings(int $status = 200, ?string $errorCode = null): void
{
    Http::fake(['*/embeddings' => $status === 200
        ? Http::response(['data' => [['embedding' => array_fill(0, 1536, 0.01)]], 'usage' => ['prompt_tokens' => 1]])
        : Http::response(['error' => ['code' => $errorCode, 'message' => 'Fake error']], $status),
    ]);
}
