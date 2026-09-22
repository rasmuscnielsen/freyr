<?php

use App\Mcp\Servers\FreyrServer;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListPostings;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
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
    Passport::actingAs($user, ['mcp:use']);

    FreyrServer::tools()
        ->assertRegistered(ListPostings::class)
        ->assertNotRegistered(ListCategories::class);
});

test('read tools require a read or mcp:use scope', function () {
    $user = User::factory()->create();

    Passport::actingAs($user, ['write']);

    FreyrServer::tool(ListPostings::class)->assertNotRegistered();
    expect(mcpCall('get-posting', ['id' => 1])['isError'])->toBeTrue();

    Passport::actingAs($user, ['read']);

    FreyrServer::tool(ListPostings::class)->assertOk();
});

test('write tools require a write or mcp:use scope', function () {
    $user = User::factory()->create();
    $subcategory = Subcategory::factory()->create();

    Passport::actingAs($user, ['read']);

    $result = mcpCall('categorize-postings', [
        'ids' => [1],
        'categorized_subcategory_id' => $subcategory->id,
    ]);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('not available');

    Passport::actingAs($user, ['mcp:use']);

    $result = mcpCall('categorize-postings', [
        'ids' => [1],
        'categorized_subcategory_id' => $subcategory->id,
    ]);

    expect($result['isError'])->toBeFalse();
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

    Passport::actingAs($user, ['read']);

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

    Passport::actingAs($user, ['read']);

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

    Passport::actingAs($user, ['read']);

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

    Passport::actingAs($user, ['read']);

    $result = mcpCall('list-categories');

    expect($result['structuredContent']['categories'][0]['name'])->toBe('Food')
        ->and($result['structuredContent']['categories'][0]['subcategories'][0]['hints'])
        ->toBe('Supermarkets and food stores');
});

test('list-bank-accounts returns only the acting user accounts', function () {
    $user = User::factory()->create();
    BankAccount::factory()->for($user)->create(['name' => 'My account']);
    BankAccount::factory()->create(['name' => 'Foreign account']);

    Passport::actingAs($user, ['read']);

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

    Passport::actingAs($user, ['write']);

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

    Passport::actingAs($user, ['write']);

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

    Passport::actingAs($user, ['write']);

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

    Passport::actingAs($user, ['write']);

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

    Passport::actingAs($user, ['write']);

    $result = mcpCall('edit-posting', [
        'id' => $posting->id,
        'amount' => '-250.00',
    ]);

    expect($result['isError'])->toBeFalse();

    $posting->refresh();
    expect((float) $posting->amount)->toBe(-250.00)
        ->and((float) $posting->balance)->toBe(750.00)
        ->and((float) $account->refresh()->current_balance)->toBe(750.00);
});

test('tools are not registered without an authenticated user or sole user', function () {
    User::factory()->count(2)->create();

    FreyrServer::tool(ListPostings::class)->assertNotRegistered();
    expect(mcpCall('get-posting', ['id' => 1])['isError'])->toBeTrue();
});
