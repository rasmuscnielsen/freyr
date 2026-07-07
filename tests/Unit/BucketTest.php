<?php

use App\Enums\Bucket;
use App\Enums\CategoryType;
use App\Enums\ExpenseType;

test('it maps every category and expense type combination to a bucket', function (CategoryType $categoryType, ?ExpenseType $expenseType, ?Bucket $expected) {
    expect(Bucket::forCategory($categoryType, $expenseType))->toBe($expected);
})->with([
    'income (no expense type)' => [CategoryType::Income, null, Bucket::Income],
    'income (fixed ignored)' => [CategoryType::Income, ExpenseType::Fixed, Bucket::Income],
    'income (variable ignored)' => [CategoryType::Income, ExpenseType::Variable, Bucket::Income],

    'expense fixed → bills' => [CategoryType::Expense, ExpenseType::Fixed, Bucket::Bills],
    'expense variable → consumption' => [CategoryType::Expense, ExpenseType::Variable, Bucket::Consumption],
    'expense null → consumption' => [CategoryType::Expense, null, Bucket::Consumption],

    'investment (no expense type)' => [CategoryType::Investment, null, Bucket::Investments],
    'investment (fixed ignored)' => [CategoryType::Investment, ExpenseType::Fixed, Bucket::Investments],
    'investment (variable ignored)' => [CategoryType::Investment, ExpenseType::Variable, Bucket::Investments],

    'saving (no expense type)' => [CategoryType::Saving, null, Bucket::Savings],
    'saving (fixed ignored)' => [CategoryType::Saving, ExpenseType::Fixed, Bucket::Savings],
    'saving (variable ignored)' => [CategoryType::Saving, ExpenseType::Variable, Bucket::Savings],

    'exclude (no expense type)' => [CategoryType::Exclude, null, null],
    'exclude (fixed ignored)' => [CategoryType::Exclude, ExpenseType::Fixed, null],
    'exclude (variable ignored)' => [CategoryType::Exclude, ExpenseType::Variable, null],
]);
