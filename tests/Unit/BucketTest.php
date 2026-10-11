<?php

use App\Enums\Bucket;
use App\Enums\CategoryType;
use App\Enums\ExpenseGroup;

test('it maps every category and expense group combination to a bucket', function (CategoryType $categoryType, ?ExpenseGroup $expenseGroup, ?Bucket $expected) {
    expect(Bucket::forCategory($categoryType, $expenseGroup))->toBe($expected);
})->with([
    'income (no expense group)' => [CategoryType::Income, null, Bucket::Income],
    'income (bills ignored)' => [CategoryType::Income, ExpenseGroup::Bills, Bucket::Income],
    'income (other ignored)' => [CategoryType::Income, ExpenseGroup::Other, Bucket::Income],

    'expense bills → bills' => [CategoryType::Expense, ExpenseGroup::Bills, Bucket::Bills],
    'expense living costs → living costs' => [CategoryType::Expense, ExpenseGroup::LivingCosts, Bucket::LivingCosts],
    'expense lifestyle → lifestyle' => [CategoryType::Expense, ExpenseGroup::Lifestyle, Bucket::Lifestyle],
    'expense other → other' => [CategoryType::Expense, ExpenseGroup::Other, Bucket::Other],
    'expense unassigned → other' => [CategoryType::Expense, null, Bucket::Other],

    'investment (no expense group)' => [CategoryType::Investment, null, Bucket::Investments],
    'investment (bills ignored)' => [CategoryType::Investment, ExpenseGroup::Bills, Bucket::Investments],
    'investment (other ignored)' => [CategoryType::Investment, ExpenseGroup::Other, Bucket::Investments],

    'saving (no expense group)' => [CategoryType::Saving, null, Bucket::Savings],
    'saving (bills ignored)' => [CategoryType::Saving, ExpenseGroup::Bills, Bucket::Savings],
    'saving (other ignored)' => [CategoryType::Saving, ExpenseGroup::Other, Bucket::Savings],

    'exclude (no expense group)' => [CategoryType::Exclude, null, null],
    'exclude (bills ignored)' => [CategoryType::Exclude, ExpenseGroup::Bills, null],
    'exclude (other ignored)' => [CategoryType::Exclude, ExpenseGroup::Other, null],
]);
