<?php

use App\Jobs\ConvertReportingAmounts;
use App\Mcp\Servers\FreyrServer;
use App\Mcp\Tools\CreateBankAccount;
use App\Mcp\Tools\CreatePosting;
use App\Mcp\Tools\DeletePosting;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListPostings;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Tools\ToolSearch;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Passport\Passport;

function connectedAccountFor(User $user): BankAccount
{
    $account = BankAccount::factory()->for($user)->create();
    $connection = BankConnection::factory()->for($user)->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create();

    return $account;
}

/**
 * Invoke a catalog (ToolSearch-grouped) tool the way MCP clients do — grouped
 * tools are not in `tools/list` and are not callable via `tools/call`; they go
 * through `execute_tools`. Returns the decoded result entry for the call
 * (`content`, `structuredContent`, `isError`).
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function mcpCall(string $name, array $arguments = []): array
{
    $catalog = new ToolSearch(FreyrServer::CATALOG_TOOLS);

    $parentRequest = (new JsonRpcRequest('test', 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => [],
    ]))->toRequest();

    $responses = $catalog->execute([
        ['name' => $name, 'arguments' => $arguments],
    ], $parentRequest);

    $last = end($responses);
    $text = $last->content()->toArray()['text'] ?? '';
    $decoded = json_decode($text, associative: true);

    return is_array($decoded) ? ($decoded['results'][0] ?? ['isError' => true, 'raw' => $decoded])
        : ['isError' => true, 'raw' => $text];
}

test('unauthenticated requests to /mcp get a JSON 401 with WWW-Authenticate', function () {
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
    expect($response->headers->get('WWW-Authenticate'))
        ->toContain('Bearer realm="mcp"')
        ->toContain('resource_metadata=');
});

test('authenticated token requests reach the MCP server', function () {
    $user = User::factory()->create();
    Passport::actingAs($user, ['mcp:use']);

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Freyr');
});

test('list-postings is registered in tools/list while the rest are discovered via search', function () {
    $user = User::factory()->create();
    Passport::actingAs($user, ['mcp:use', 'read']);

    FreyrServer::tools()
        ->assertRegistered(ListPostings::class)
        ->assertNotRegistered(ListCategories::class);
});

test('read tools require mcp:use and read scopes', function () {
    $user = User::factory()->create();

    Passport::actingAs($user, ['read']);

    FreyrServer::tool(ListPostings::class)->assertNotRegistered();

    Passport::actingAs($user, ['mcp:use']);

    FreyrServer::tool(ListPostings::class)->assertNotRegistered();
    expect(mcpCall('get-posting', ['id' => 1])['isError'])->toBeTrue();

    Passport::actingAs($user, ['mcp:use', 'read']);

    FreyrServer::tool(ListPostings::class)->assertOk();
});

test('write tools require mcp:use and write scopes', function () {
    $user = User::factory()->create();
    $subcategory = Subcategory::factory()->create();

    $categorize = fn () => mcpCall('categorize-postings', [
        'ids' => [1],
        'categorized_subcategory_id' => $subcategory->id,
    ]);

    Passport::actingAs($user, ['mcp:use']);
    expect($categorize()['isError'])->toBeTrue();

    Passport::actingAs($user, ['mcp:use', 'read']);
    expect($categorize()['isError'])->toBeTrue()
        ->and($categorize()['content'][0]['text'])->toContain('not available');

    Passport::actingAs($user, ['mcp:use', 'write']);
    expect($categorize()['isError'])->toBeFalse();
});

test('a sole user can use all tools over stdio without authenticating', function () {
    $user = User::factory()->create();
    $subcategory = Subcategory::factory()->create();
    $posting = Posting::factory()
        ->forAccount(BankAccount::factory()->for($user)->create())
        ->uncategorized()
        ->create();

    FreyrServer::tool(ListPostings::class)->assertOk();

    $result = mcpCall('categorize-postings', [
        'ids' => [$posting->id],
        'categorized_subcategory_id' => $subcategory->id,
    ]);

    expect($result['isError'])->toBeFalse();
    expect($posting->refresh()->categorized_subcategory_id)->toBe($subcategory->id);
});

test('list-postings returns only the acting user postings', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create(['name' => 'Checking']);
    $posting = Posting::factory()->forAccount($account)->create(['description' => 'Rent']);
    Posting::factory()->create(['description' => 'Foreign posting']);

    Passport::actingAs($user, ['mcp:use', 'read']);

    FreyrServer::tool(ListPostings::class)
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('postings', 1)
            ->where('postings.0.id', $posting->id)
            ->where('postings.0.description', 'Rent')
            ->where('pagination.total', 1)
            ->etc());
});

test('list-postings filters uncategorized postings', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $subcategory = Subcategory::factory()->create();
    Posting::factory()->forAccount($account)->uncategorized()->create();
    Posting::factory()->forAccount($account)->categorizedAs($subcategory)->create();

    Passport::actingAs($user, ['mcp:use', 'read']);

    FreyrServer::tool(ListPostings::class, ['uncategorized' => true])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('postings', 1)
            ->where('pagination.total', 1)
            ->etc());
});

test('get-posting returns detail and errors on foreign postings', function () {
    $user = User::factory()->create();
    $subcategory = Subcategory::factory()->create(['name' => 'Groceries']);
    $posting = Posting::factory()
        ->forAccount(BankAccount::factory()->for($user)->create())
        ->categorizedAs($subcategory)
        ->create();
    $foreign = Posting::factory()->create();

    Passport::actingAs($user, ['mcp:use', 'read']);

    $result = mcpCall('get-posting', ['id' => $posting->id]);

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['posting']['id'])->toBe($posting->id)
        ->and($result['structuredContent']['posting']['subcategory']['name'])->toBe('Groceries');

    $result = mcpCall('get-posting', ['id' => $foreign->id]);

    expect($result['isError'])->toBeTrue();
});

test('list-categories exposes the categorization vocabulary', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Food']);
    Subcategory::factory()->forCategory($category)->create([
        'name' => 'Groceries',
        'hints' => 'Supermarkets and food stores',
    ]);

    Passport::actingAs($user, ['mcp:use', 'read']);

    $result = mcpCall('list-categories');

    expect($result['structuredContent']['categories'][0]['name'])->toBe('Food')
        ->and($result['structuredContent']['categories'][0]['subcategories'][0]['hints'])
        ->toBe('Supermarkets and food stores');
});

test('list-bank-accounts returns only the acting user accounts', function () {
    $user = User::factory()->create();
    BankAccount::factory()->for($user)->create(['name' => 'My account']);
    BankAccount::factory()->create(['name' => 'Foreign account']);

    Passport::actingAs($user, ['mcp:use', 'read']);

    $result = mcpCall('list-bank-accounts');

    expect($result['structuredContent']['accounts'])->toHaveCount(1)
        ->and($result['structuredContent']['accounts'][0]['name'])->toBe('My account')
        ->and($result['structuredContent']['accounts'][0]['has_bank_connection'])->toBeFalse();
});

test('categorize-postings applies to owned postings and reports skipped ids', function () {
    $user = User::factory()->create();
    $subcategory = Subcategory::factory()->create(['name' => 'Rent']);
    $account = BankAccount::factory()->for($user)->create();
    $owned = Posting::factory()->forAccount($account)->uncategorized()->create();
    $foreign = Posting::factory()->uncategorized()->create();

    Passport::actingAs($user, ['mcp:use', 'write']);

    $result = mcpCall('categorize-postings', [
        'ids' => [$owned->id, $foreign->id],
        'categorized_subcategory_id' => $subcategory->id,
    ]);

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['applied'])->toBe([$owned->id])
        ->and($result['structuredContent']['skipped'])->toBe([$foreign->id])
        ->and($result['structuredContent']['categorized_subcategory']['name'])->toBe('Rent');

    expect($owned->refresh()->categorized_subcategory_id)->toBe($subcategory->id)
        ->and($owned->categorized_by)->toBe('mcp')
        ->and($foreign->refresh()->categorized_subcategory_id)->toBeNull();
});

test('categorize-postings with null uncategorizes postings', function () {
    $user = User::factory()->create();
    $subcategory = Subcategory::factory()->create();
    $posting = Posting::factory()
        ->forAccount(BankAccount::factory()->for($user)->create())
        ->categorizedAs($subcategory)
        ->create();

    Passport::actingAs($user, ['mcp:use', 'write']);

    $result = mcpCall('categorize-postings', [
        'ids' => [$posting->id],
        'categorized_subcategory_id' => null,
    ]);

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['applied'])->toBe([$posting->id])
        ->and($result['structuredContent']['categorized_subcategory'])->toBeNull();

    expect($posting->refresh()->categorized_subcategory_id)->toBeNull()
        ->and($posting->categorized_by)->toBeNull();
});

test('edit-posting edits comment and category on synced accounts', function () {
    $user = User::factory()->create();
    $account = connectedAccountFor($user);
    $subcategory = Subcategory::factory()->create();
    $posting = Posting::factory()->forAccount($account)->uncategorized()->create();

    Passport::actingAs($user, ['mcp:use', 'write']);

    $result = mcpCall('edit-posting', [
        'id' => $posting->id,
        'comment' => 'One-off',
        'is_extraordinary' => true,
        'categorized_subcategory_id' => $subcategory->id,
    ]);

    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['posting']['id'])->toBe($posting->id);

    expect($posting->refresh()->comment)->toBe('One-off')
        ->and($posting->is_extraordinary)->toBeTrue()
        ->and($posting->categorized_subcategory_id)->toBe($subcategory->id)
        ->and($posting->categorized_by)->toBe('mcp');
});

test('edit-posting refuses amount edits on synced accounts', function () {
    $user = User::factory()->create();
    $account = connectedAccountFor($user);
    $posting = Posting::factory()->forAccount($account)->create(['amount' => '-100.00']);

    Passport::actingAs($user, ['mcp:use', 'write']);

    $result = mcpCall('edit-posting', [
        'id' => $posting->id,
        'amount' => '-50.00',
    ]);

    expect($result['isError'])->toBeTrue();
    expect((float) $posting->refresh()->amount)->toBe(-100.00);
});

test('edit-posting edits manual account postings and recalculates balances', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create([
        'opening_balance' => '1000.00',
        'current_balance' => '900.00',
    ]);
    $posting = Posting::factory()->forAccount($account)->create([
        'date' => '2026-07-01',
        'amount' => '-100.00',
        'balance' => '900.00',
    ]);

    Passport::actingAs($user, ['mcp:use', 'write']);

    $result = mcpCall('edit-posting', [
        'id' => $posting->id,
        'amount' => '-250.00',
    ]);

    expect($result['isError'])->toBeFalse();

    $posting->refresh();
    expect((float) $posting->amount)->toBe(-250.00)
        ->and((float) $posting->balance)->toBe(900.00)
        ->and((float) $account->refresh()->current_balance)->toBe(750.00);
});

test('tools are not registered without an authenticated user or sole user', function () {
    User::factory()->count(2)->create();

    FreyrServer::tool(ListPostings::class)->assertNotRegistered();
    expect(mcpCall('get-posting', ['id' => 1])['isError'])->toBeTrue();
});

test('manual creation and deletion tools are write-scoped catalog tools', function (string $name, string $tool) {
    $user = User::factory()->create();
    $catalog = new ToolSearch(FreyrServer::CATALOG_TOOLS);

    foreach ([['mcp:use'], ['mcp:use', 'read'], ['write']] as $scopes) {
        Passport::actingAs($user, $scopes);
        expect(array_column($catalog->search($name, 20)['tools'], 'name'))->not->toContain($name)
            ->and(mcpCall($name)['content'][0]['text'])->toContain('not available');
    }

    Passport::actingAs($user, ['mcp:use', 'write']);
    FreyrServer::tools()->assertNotRegistered($tool);
    expect($catalog->search($name, 1)['tools'][0]['name'])->toBe($name);
})->with([
    ['create-bank-account', CreateBankAccount::class],
    ['create-posting', CreatePosting::class],
    ['delete-posting', DeletePosting::class],
]);

test('create-bank-account creates only an owned manual account with UI defaults', function () {
    Queue::fake();
    $user = User::factory()->create();
    $other = User::factory()->create();
    Passport::actingAs($user, ['mcp:use', 'write']);

    $result = mcpCall('create-bank-account', [
        'name' => 'Cash', 'currency' => 'DKK',
        'user_id' => $other->id, 'current_balance' => '999', 'legacy_id' => 'injected',
    ]);

    expect($result['isError'])->toBeFalse();
    $account = BankAccount::query()->findOrFail($result['structuredContent']['account']['id']);
    expect($account->user_id)->toBe($user->id)
        ->and($account->legacy_id)->toStartWith('manual-')
        ->and($account->opening_balance)->toBe('0.00')
        ->and($account->current_balance)->toBe('0.00')
        ->and($result['structuredContent']['account'])->toMatchArray([
            'name' => 'Cash', 'currency' => 'DKK', 'has_bank_connection' => false,
            'is_active' => true, 'is_favorite' => false,
        ]);
    Queue::assertNotPushed(ConvertReportingAmounts::class);
});

test('create-bank-account preserves opening balances and queues foreign valuations', function () {
    Queue::fake();
    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    Passport::actingAs($user, ['mcp:use', 'write']);

    $result = mcpCall('create-bank-account', [
        'name' => 'Travel', 'currency' => 'EUR', 'opening_balance' => '-123.45',
        'bank_name' => 'Cash wallet', 'is_active' => false, 'is_favorite' => true,
    ]);

    expect($result['isError'])->toBeFalse();
    $account = BankAccount::query()->findOrFail($result['structuredContent']['account']['id']);
    expect($account->opening_balance)->toBe('-123.45')
        ->and($account->current_balance)->toBe('-123.45')
        ->and($result['structuredContent']['account'])->toMatchArray([
            'bank_name' => 'Cash wallet', 'is_active' => false, 'is_favorite' => true,
        ]);
    Queue::assertPushed(ConvertReportingAmounts::class, fn ($job) => $job->userId === $user->id);
});

test('create-bank-account uses UI validation without creating an account', function (array $attributes) {
    Passport::actingAs(User::factory()->create(), ['mcp:use', 'write']);

    $result = mcpCall('create-bank-account', ['name' => 'Cash', 'currency' => 'DKK', ...$attributes]);

    expect($result['isError'])->toBeTrue()
        ->and(BankAccount::query()->count())->toBe(0);
})->with([
    'missing name' => [['name' => '']],
    'invalid currency' => [['currency' => 'EURO']],
    'fractional precision' => [['opening_balance' => '1.234']],
    'balance overflow' => [['opening_balance' => '10000000000000.00']],
]);

test('create and delete postings preserve account-currency balances and reporting amounts', function (string $currency, ?string $openingBalance) {
    Queue::fake();
    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    $account = BankAccount::factory()->for($user)->create([
        'currency' => $currency, 'opening_balance' => $openingBalance, 'current_balance' => '200.00',
    ]);
    $subcategory = Subcategory::factory()->create();
    Passport::actingAs($user, ['mcp:use', 'read', 'write']);

    $result = mcpCall('create-posting', [
        'bank_account_id' => $account->id, 'date' => '2026-07-02',
        'description' => 'Lunch', 'amount' => '-12.35',
        'categorized_subcategory_id' => $subcategory->id, 'comment' => 'Travel lunch',
        'is_extraordinary' => true, 'user_id' => 999, 'source' => 'bank',
    ]);

    expect($result['isError'])->toBeFalse();
    $posting = Posting::query()->findOrFail($result['structuredContent']['posting']['id']);
    expect($posting->user_id)->toBe($user->id)
        ->and($posting->bank_account_id)->toBe($account->id)
        ->and($posting->original_amount)->toBe('-12.35')
        ->and($posting->original_currency)->toBe($currency)
        ->and($posting->currency)->toBe('DKK')
        ->and($posting->amount)->toBe($currency === 'DKK' ? '-12.35' : null)
        ->and($posting->source)->toBe('manual')
        ->and($posting->balance)->toBeNull()
        ->and($posting->categorized_by)->toBe('mcp')
        ->and($result['structuredContent']['posting'])->toMatchArray([
            'date' => '2026-07-02', 'description' => 'Lunch', 'comment' => 'Travel lunch',
            'is_extraordinary' => true, 'subcategory' => [
                'id' => $subcategory->id, 'name' => $subcategory->name, 'type' => $subcategory->category->type->value,
            ],
        ])
        ->and($account->refresh()->current_balance)->toBe('187.65');

    if ($currency === 'DKK') {
        Queue::assertNotPushed(ConvertReportingAmounts::class);
    } else {
        Queue::assertPushed(ConvertReportingAmounts::class, fn ($job) => $job->userId === $user->id);
    }

    $kept = Posting::factory()->forAccount($account)->create([
        'date' => '2026-07-01', 'original_amount' => '30.10', 'counter_entry_posting_id' => $posting->id,
    ]);
    // Only anchored balances include the new fixture movement automatically.
    $result = mcpCall('delete-posting', ['id' => $posting->id]);
    expect($result['isError'])->toBeFalse()
        ->and($result['structuredContent']['deleted_id'])->toBe($posting->id)
        ->and($posting->fresh()->trashed())->toBeTrue()
        ->and($kept->refresh()->counter_entry_posting_id)->toBeNull()
        ->and($account->refresh()->current_balance)->toBe($openingBalance === null ? '200.00' : '230.10')
        ->and(mcpCall('get-posting', ['id' => $posting->id])['isError'])->toBeTrue()
        ->and(mcpCall('delete-posting', ['id' => $posting->id])['isError'])->toBeTrue();

    if ($openingBalance !== null) {
        expect($account->last_known_posting_date->toDateString())->toBe('2026-07-01');
    }
})->with([
    'same currency anchored' => ['DKK', '200.00'],
    'foreign currency anchored' => ['EUR', '200.00'],
    'foreign currency snapshot' => ['EUR', null],
]);

test('manual posting tools refuse foreign, missing and bank-synced targets without mutations', function (string $target) {
    $user = User::factory()->create();
    $account = match ($target) {
        'synced' => connectedAccountFor($user),
        'foreign' => BankAccount::factory()->create(),
        default => BankAccount::factory()->for($user)->create(),
    };
    $posting = Posting::factory()->forAccount($account)->create();
    $before = $account->refresh()->getAttributes();
    Passport::actingAs($user, ['mcp:use', 'write']);

    $create = mcpCall('create-posting', [
        'bank_account_id' => $target === 'missing' ? 999999 : $account->id,
        'date' => '2026-07-01', 'description' => 'Forbidden', 'amount' => '42.10',
    ]);
    $delete = mcpCall('delete-posting', ['id' => $target === 'missing' ? 999999 : $posting->id]);

    expect($create['isError'])->toBeTrue()
        ->and($delete['isError'])->toBeTrue()
        ->and(Posting::query()->count())->toBe(1)
        ->and(Posting::onlyTrashed()->count())->toBe(0)
        ->and($account->refresh()->getAttributes())->toBe($before);
})->with(['foreign', 'missing', 'synced']);

test('create-posting rejects invalid UI fields without changing the balance', function (array $attributes) {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create(['opening_balance' => '100.00', 'current_balance' => '100.00']);
    Passport::actingAs($user, ['mcp:use', 'write']);

    $result = mcpCall('create-posting', [
        'bank_account_id' => $account->id, 'date' => '2026-07-01',
        'description' => 'Lunch', 'amount' => '-12.35', ...$attributes,
    ]);

    expect($result['isError'])->toBeTrue()
        ->and(Posting::query()->count())->toBe(0)
        ->and($account->refresh()->current_balance)->toBe('100.00');
})->with([
    'bad date' => [['date' => 'not-a-date']],
    'missing description' => [['description' => '']],
    'excess precision' => [['amount' => '1.234']],
    'unknown category' => [['categorized_subcategory_id' => 999999]],
    'long comment' => [['comment' => str_repeat('x', 2001)]],
]);
