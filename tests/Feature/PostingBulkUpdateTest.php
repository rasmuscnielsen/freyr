<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;

test('multiple postings can be categorized in one request', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $category = Category::factory()->create();
    $subcategory = Subcategory::factory()->forCategory($category)->create();
    $postings = Posting::factory()->count(3)->forAccount($account)->uncategorized()->create();

    $this->actingAs($user)
        ->patch(route('postings.bulk-update'), [
            'ids' => $postings->pluck('id')->all(),
            'categorized_subcategory_id' => $subcategory->id,
        ])
        ->assertRedirect();

    $postings->each(function (Posting $posting) use ($subcategory): void {
        expect($posting->fresh()->categorized_subcategory_id)->toBe($subcategory->id);
    });
});

test('bulk categorization can clear categories', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $category = Category::factory()->create();
    $subcategory = Subcategory::factory()->forCategory($category)->create();
    $postings = Posting::factory()->count(2)->forAccount($account)->categorizedAs($subcategory)->create();

    $this->actingAs($user)
        ->patch(route('postings.bulk-update'), [
            'ids' => $postings->pluck('id')->all(),
            'categorized_subcategory_id' => null,
        ])
        ->assertRedirect();

    $postings->each(function (Posting $posting): void {
        expect($posting->fresh()->categorized_subcategory_id)->toBeNull();
    });
});

test('bulk categorization ignores postings owned by other users', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $otherAccount = BankAccount::factory()->for($otherUser)->create();
    $category = Category::factory()->create();
    $subcategory = Subcategory::factory()->forCategory($category)->create();
    $ownPosting = Posting::factory()->forAccount($account)->uncategorized()->create();
    $foreignPosting = Posting::factory()->forAccount($otherAccount)->uncategorized()->create();

    $this->actingAs($user)
        ->patch(route('postings.bulk-update'), [
            'ids' => [$ownPosting->id, $foreignPosting->id],
            'categorized_subcategory_id' => $subcategory->id,
        ])
        ->assertRedirect();

    expect($ownPosting->fresh()->categorized_subcategory_id)->toBe($subcategory->id)
        ->and($foreignPosting->fresh()->categorized_subcategory_id)->toBeNull();
});

test('bulk categorization validates the subcategory and ids', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $posting = Posting::factory()->forAccount($account)->uncategorized()->create();

    $this->actingAs($user)
        ->from(route('postings.index'))
        ->patch(route('postings.bulk-update'), [
            'ids' => [$posting->id],
            'categorized_subcategory_id' => 999999,
        ])
        ->assertSessionHasErrors('categorized_subcategory_id');

    $this->actingAs($user)
        ->from(route('postings.index'))
        ->patch(route('postings.bulk-update'), [
            'ids' => [],
            'categorized_subcategory_id' => null,
        ])
        ->assertSessionHasErrors('ids');
});

test('guests cannot bulk update postings', function () {
    User::factory()->create();

    $this->patch(route('postings.bulk-update'), [
        'ids' => [1],
        'categorized_subcategory_id' => null,
    ])->assertRedirect(route('login'));
});
