<?php

use App\Actions\DeletePosting;
use App\Enums\CategoryType;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use App\Services\Spiir\Importer;
use Database\Seeders\DefaultCategoriesSeeder;
use Illuminate\Support\Carbon;

test('it imports a spiir export idempotently', function () {
    $path = base_path('tests/Fixtures/spiir-export');

    app(Importer::class)->importExport($path);

    expect(Category::query()->count())->toBe(3)
        ->and(Subcategory::query()->count())->toBe(3)
        ->and(BankAccount::query()->count())->toBe(2)
        ->and(Posting::query()->count())->toBe(7)
        ->and(User::query()->count())->toBe(1);

    $user = User::query()->firstOrFail();
    expect($user->email)->toBe('jane@example.com')
        ->and($user->name)->toBe('Jane Importer');

    $travelCard = BankAccount::query()->where('legacy_id', 'acc-2')->firstOrFail();
    expect($travelCard->currency)->toBe('EUR')
        ->and($travelCard->bank_name)->toBe('Other bank')
        // Keep Spiir's account snapshot, but do not infer an opening balance
        // or recalculate the exported posting history from it.
        ->and($travelCard->opening_balance)->toBeNull()
        ->and((float) $travelCard->current_balance)->toBe(250.00);

    $salary = Posting::query()->where('legacy_id', 'post-1')->firstOrFail();
    expect((float) $salary->amount)->toBe(30000.0)
        ->and((float) $salary->balance)->toBe(31500.50)
        ->and($salary->date->toDateString())->toBe('2024-01-01')
        ->and($salary->categorizedSubcategory->name)->toBe('Salary');

    // The foreign account keeps the EUR account movement in the original
    // pair and the export's DKK figure as the reporting amount.
    $transferIn = Posting::query()->where('legacy_id', 'post-4')->firstOrFail();
    expect((float) $transferIn->original_amount)->toBe(134.20)
        ->and($transferIn->original_currency)->toBe('EUR')
        ->and((float) $transferIn->amount)->toBe(1000.0)
        ->and($transferIn->currency)->toBe('DKK')
        ->and($transferIn->balance)->toBeNull()
        ->and(data_get($transferIn->extra_data, 'spiir_source.balance'))->toBe('2862,29');

    $cafe = Posting::query()->where('legacy_id', 'post-5')->firstOrFail();
    expect((float) $cafe->original_amount)->toBe(-12.50)
        ->and($cafe->original_currency)->toBe('EUR')
        ->and((float) $cafe->amount)->toBe(-93.20);

    $transferOut = Posting::query()->where('legacy_id', 'post-3')->firstOrFail();
    $transferIn = Posting::query()->where('legacy_id', 'post-4')->firstOrFail();
    expect($transferOut->counter_entry_posting_id)->toBe($transferIn->id)
        ->and($transferIn->counter_entry_posting_id)->toBe($transferOut->id);

    expect(Posting::query()->where('legacy_id', 'post-6')->exists())->toBeFalse()
        ->and(Posting::query()->where('legacy_id', 'post-7')->firstOrFail()->balance)->toBeNull()
        ->and(Posting::query()->where('legacy_id', 'post-8')->firstOrFail()->balance)->toBeNull()
        ->and(BankAccount::query()->where('legacy_id', 'acc-1')->firstOrFail()->current_balance)->toBe('1500.50');

    // Spiir's legacy 'Transfer' category type collapses onto Exclude.
    expect(Category::query()->where('legacy_id', '300')->firstOrFail()->type)->toBe(CategoryType::Exclude);

    app(Importer::class)->importExport($path);

    expect(Category::query()->count())->toBe(3)
        ->and(Subcategory::query()->count())->toBe(3)
        ->and(BankAccount::query()->count())->toBe(2)
        ->and(Posting::query()->count())->toBe(7)
        ->and(User::query()->count())->toBe(1);
});

test('re-import preserves Spiir snapshots but recalculates explicitly anchored manual totals', function () {
    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    $path = base_path('tests/Fixtures/spiir-export');
    $importer = app(Importer::class);

    $importer->importExport($path, $user);
    $account = BankAccount::query()->where('legacy_id', 'acc-1')->firstOrFail();
    $account->update(['current_balance' => '777.00']);

    $importer->importExport($path, $user);

    expect($account->refresh()->opening_balance)->toBeNull()
        ->and((float) $account->current_balance)->toBe(777.00)
        ->and(Posting::query()->where('legacy_id', 'post-8')->firstOrFail()->balance)->toBeNull();

    $account->update(['opening_balance' => '1000.00', 'current_balance' => '0.00']);
    $importer->importExport($path, $user);
    $expectedTotal = bcadd('1000.00', (string) $account->postings()->sum('original_amount'), 2);

    expect($account->refresh()->current_balance)->toBe($expectedTotal);
});

test('it imports a spiir export for an explicit user', function () {
    $user = User::factory()->create();
    $path = base_path('tests/Fixtures/spiir-export');

    $summary = app(Importer::class)->importExport($path, $user);

    expect($summary['user_id'])->toBe($user->id)
        ->and(User::query()->count())->toBe(1)
        ->and(Posting::query()->count())->toBe(7);
});

test('it fails when the export files are missing', function () {
    app(Importer::class)->importExport(base_path('tests/Fixtures/does-not-exist'));
})->throws(RuntimeException::class, 'Missing Spiir export file');

test('spiir account group duplicates are skipped without collapsing identical unflagged postings', function () {
    $path = sys_get_temp_dir().'/freyr-spiir-duplicates-'.bin2hex(random_bytes(6));
    mkdir($path);
    foreach (glob(base_path('tests/Fixtures/spiir-export/*')) as $fixture) {
        copy($fixture, $path.'/'.basename($fixture));
    }
    $lines = file($path.'/Posting.csv', FILE_IGNORE_NEW_LINES);
    $salary = str_getcsv($lines[1], ';', '"', '\\');
    $lines[0] .= ';IsAccountGroupDuplicate';
    foreach ($lines as $index => $line) {
        if ($index > 0) {
            $lines[$index] = $line.';False';
        }
    }
    $salary[0] = 'post-identical';
    $lines[] = implode(';', $salary).';False';
    $salary[0] = 'post-duplicate';
    $salary[1] = 'acc-2';
    $lines[] = implode(';', $salary).';True';
    file_put_contents($path.'/Posting.csv', implode("\n", $lines)."\n");
    $user = User::factory()->create();

    try {
        foreach (range(1, 2) as $run) {
            $summary = app(Importer::class)->importExport($path, $user);

            expect($summary['postings'])->toBe(8)
                ->and(Posting::query()->count())->toBe(8)
                ->and(Posting::query()->where('legacy_id', 'post-duplicate')->exists())->toBeFalse()
                ->and(Posting::query()->whereIn('legacy_id', ['post-1', 'post-identical'])->count())->toBe(2);
        }
    } finally {
        foreach (glob($path.'/*') as $file) {
            unlink($file);
        }
        rmdir($path);
    }
});

test('spiir placeholder external IDs remain missing after repeated imports', function () {
    $path = sys_get_temp_dir().'/freyr-spiir-placeholder-'.bin2hex(random_bytes(6));
    mkdir($path);
    foreach (glob(base_path('tests/Fixtures/spiir-export/*')) as $fixture) {
        copy($fixture, $path.'/'.basename($fixture));
    }
    $lines = file($path.'/Posting.csv', FILE_IGNORE_NEW_LINES);
    $lines[0] .= ';ExternalPostingId';
    foreach ($lines as $index => $line) {
        if ($index > 0) {
            $lines[$index] = $line.';*** hidden ***';
        }
    }
    file_put_contents($path.'/Posting.csv', implode("\n", $lines)."\n");
    $user = User::factory()->create();

    try {
        app(Importer::class)->importExport($path, $user);
        app(Importer::class)->importExport($path, $user);

        expect(Posting::query()->count())->toBe(7)
            ->and(Posting::query()->whereNotNull('external_posting_id')->count())->toBe(0);
    } finally {
        foreach (glob($path.'/*') as $file) {
            unlink($file);
        }
        rmdir($path);
    }
});

test('postings losing a removed default category are marked as updated', function () {
    $user = User::factory()->create();
    $this->seed(DefaultCategoriesSeeder::class);
    $subcategory = Subcategory::query()->where('legacy_id', 'like', DefaultCategoriesSeeder::SUBCATEGORY_LEGACY_ID_PREFIX.'%')->firstOrFail();

    Carbon::setTestNow('2026-05-01 10:00:00');
    $manual = Posting::factory()->forAccount(BankAccount::factory()->for($user)->create())->categorizedAs($subcategory)->create();

    Carbon::setTestNow('2026-05-02 10:00:00');
    app(Importer::class)->importExport(base_path('tests/Fixtures/spiir-export'), $user);

    expect($manual->fresh())
        ->categorized_subcategory_id->toBeNull()
        ->updated_at->toDateTimeString()->toBe('2026-05-02 10:00:00');
});

test('a re-import revives deleted postings in place and leaves other trashed postings alone', function () {
    $user = User::factory()->create();
    $path = base_path('tests/Fixtures/spiir-export');

    app(Importer::class)->importExport($path, $user);
    $ids = Posting::query()->orderBy('id')->pluck('id')->all();
    Posting::query()->get()->each->delete();
    $unrelated = Posting::factory()->create(['legacy_id' => 'elsewhere']);
    $unrelated->delete();

    expect(app(Importer::class)->canImport())->toBeTrue();

    app(Importer::class)->importExport($path, $user);

    // Same ids, so sync clients see an update, not a duplicate.
    expect(Posting::query()->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(Posting::onlyTrashed()->pluck('id')->all())->toBe([$unrelated->id]);
});

test('a re-import never links a posting to a deleted counter entry', function () {
    $user = User::factory()->create();
    app(Importer::class)->importExport(base_path('tests/Fixtures/spiir-export'), $user);
    app(DeletePosting::class)->handle(Posting::query()->where('legacy_id', 'post-4')->firstOrFail());

    // An export without the deleted counter entry leaves it trashed.
    $path = sys_get_temp_dir().'/spiir-export-'.uniqid();
    mkdir($path);
    foreach (glob(base_path('tests/Fixtures/spiir-export/*')) as $fixture) {
        copy($fixture, $path.'/'.basename($fixture));
    }
    $postings = file($path.'/Posting.csv');
    file_put_contents($path.'/Posting.csv', implode('', array_filter($postings, fn (string $line) => ! str_starts_with($line, 'post-4;'))));

    app(Importer::class)->importExport($path, $user);

    expect(Posting::query()->where('legacy_id', 'post-3')->firstOrFail()->counter_entry_posting_id)->toBeNull();
});
