<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a sensible default category tree for new installations. Safe to run
 * in production and idempotent: rows are keyed by legacy_id, so re-running
 * updates names in place and never duplicates. Spiir imports are unaffected
 * (they use their own legacy ids).
 */
class DefaultCategoriesSeeder extends Seeder
{
    use WithoutModelEvents;

    public const string CATEGORY_LEGACY_ID_PREFIX = 'default-category-';

    public const string SUBCATEGORY_LEGACY_ID_PREFIX = 'default-subcategory-';

    public function run(): void
    {
        foreach ($this->categoryDefinitions() as $categoryDefinition) {
            $category = Category::query()->updateOrCreate(
                ['legacy_id' => self::CATEGORY_LEGACY_ID_PREFIX.$categoryDefinition['key']],
                [
                    'name' => $categoryDefinition['name'],
                    'type' => $categoryDefinition['type'],
                    'hyphenated_name' => Str::slug($categoryDefinition['name']),
                ],
            );

            foreach ($categoryDefinition['subcategories'] as $subcategoryDefinition) {
                Subcategory::query()->updateOrCreate(
                    ['legacy_id' => self::SUBCATEGORY_LEGACY_ID_PREFIX.$subcategoryDefinition['key']],
                    [
                        'category_id' => $category->id,
                        'name' => $subcategoryDefinition['name'],
                        'hyphenated_name' => Str::slug($subcategoryDefinition['name']),
                        'type' => $categoryDefinition['type'],
                        'expense_type' => $subcategoryDefinition['expense_type'] ?? null,
                        'hints' => null,
                        'is_ignored' => $subcategoryDefinition['is_ignored'] ?? false,
                    ],
                );
            }
        }
    }

    /**
     * @return list<array{key:string,name:string,type:string,subcategories:list<array{key:string,name:string,expense_type?:string|null,is_ignored?:bool}>}>
     */
    private function categoryDefinitions(): array
    {
        return [
            [
                'key' => 'income',
                'name' => 'Income',
                'type' => 'Income',
                'subcategories' => [
                    ['key' => 'salary', 'name' => 'Salary'],
                    ['key' => 'pension_benefits', 'name' => 'Pension & benefits'],
                    ['key' => 'reimbursements', 'name' => 'Reimbursements'],
                    ['key' => 'gifts_received', 'name' => 'Gifts received'],
                    ['key' => 'other_income', 'name' => 'Other income'],
                ],
            ],
            [
                'key' => 'housing',
                'name' => 'Housing',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'rent', 'name' => 'Rent', 'expense_type' => 'Fixed'],
                    ['key' => 'mortgage', 'name' => 'Mortgage', 'expense_type' => 'Fixed'],
                    ['key' => 'utilities', 'name' => 'Utilities', 'expense_type' => 'Fixed'],
                    ['key' => 'internet', 'name' => 'Internet', 'expense_type' => 'Fixed'],
                    ['key' => 'phone', 'name' => 'Phone', 'expense_type' => 'Fixed'],
                    ['key' => 'insurance', 'name' => 'Insurance', 'expense_type' => 'Fixed'],
                    ['key' => 'home', 'name' => 'Home & garden', 'expense_type' => 'Variable'],
                ],
            ],
            [
                'key' => 'food',
                'name' => 'Food & groceries',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'groceries', 'name' => 'Groceries', 'expense_type' => 'Variable'],
                    ['key' => 'restaurants', 'name' => 'Restaurants', 'expense_type' => 'Variable'],
                    ['key' => 'takeaway', 'name' => 'Takeaway', 'expense_type' => 'Variable'],
                    ['key' => 'coffee', 'name' => 'Coffee & snacks', 'expense_type' => 'Variable'],
                ],
            ],
            [
                'key' => 'transport',
                'name' => 'Transport',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'transport', 'name' => 'Public transport', 'expense_type' => 'Variable'],
                    ['key' => 'fuel', 'name' => 'Fuel', 'expense_type' => 'Variable'],
                    ['key' => 'car', 'name' => 'Car & maintenance', 'expense_type' => 'Variable'],
                    ['key' => 'car_fixed', 'name' => 'Car insurance & tax', 'expense_type' => 'Fixed'],
                    ['key' => 'taxi', 'name' => 'Taxi & ride sharing', 'expense_type' => 'Variable'],
                ],
            ],
            [
                'key' => 'personal',
                'name' => 'Personal',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'clothing', 'name' => 'Clothing', 'expense_type' => 'Variable'],
                    ['key' => 'personal_care', 'name' => 'Personal care', 'expense_type' => 'Variable'],
                    ['key' => 'fitness', 'name' => 'Fitness & sports', 'expense_type' => 'Fixed'],
                    ['key' => 'subscriptions', 'name' => 'Subscriptions & memberships', 'expense_type' => 'Fixed'],
                    ['key' => 'electronics', 'name' => 'Electronics', 'expense_type' => 'Variable'],
                    ['key' => 'gifts_given', 'name' => 'Gifts & charity', 'expense_type' => 'Variable'],
                    ['key' => 'education', 'name' => 'Education', 'expense_type' => 'Variable'],
                ],
            ],
            [
                'key' => 'leisure',
                'name' => 'Leisure',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'streaming', 'name' => 'Streaming', 'expense_type' => 'Fixed'],
                    ['key' => 'entertainment', 'name' => 'Entertainment & culture', 'expense_type' => 'Variable'],
                    ['key' => 'hobbies', 'name' => 'Hobbies', 'expense_type' => 'Variable'],
                    ['key' => 'travel', 'name' => 'Travel', 'expense_type' => 'Variable'],
                ],
            ],
            [
                'key' => 'health',
                'name' => 'Health',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'pharmacy', 'name' => 'Pharmacy', 'expense_type' => 'Variable'],
                    ['key' => 'doctor', 'name' => 'Doctor & dentist', 'expense_type' => 'Variable'],
                    ['key' => 'glasses', 'name' => 'Glasses & contacts', 'expense_type' => 'Variable'],
                ],
            ],
            [
                'key' => 'family',
                'name' => 'Family & children',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'childcare', 'name' => 'Childcare', 'expense_type' => 'Fixed'],
                    ['key' => 'kids', 'name' => 'Kids activities & gear', 'expense_type' => 'Variable'],
                    ['key' => 'pets', 'name' => 'Pets', 'expense_type' => 'Variable'],
                ],
            ],
            [
                'key' => 'financial',
                'name' => 'Financial',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'bank_fees', 'name' => 'Bank fees', 'expense_type' => 'Fixed'],
                    ['key' => 'loan_payments', 'name' => 'Loan payments', 'expense_type' => 'Fixed'],
                    ['key' => 'other_expenses', 'name' => 'Other expenses', 'expense_type' => 'Variable'],
                    ['key' => 'cash_withdrawal', 'name' => 'Cash withdrawal', 'expense_type' => 'Variable'],
                ],
            ],
            [
                'key' => 'saving',
                'name' => 'Saving',
                'type' => 'Saving',
                'subcategories' => [
                    ['key' => 'emergency_fund', 'name' => 'Emergency fund'],
                    ['key' => 'investments', 'name' => 'Investments'],
                    ['key' => 'pension_savings', 'name' => 'Pension savings'],
                ],
            ],
            [
                'key' => 'transfer',
                'name' => 'Transfers',
                'type' => 'Transfer',
                'subcategories' => [
                    ['key' => 'internal_transfer', 'name' => 'Internal transfer', 'is_ignored' => true],
                    ['key' => 'credit_card_payment', 'name' => 'Credit card payment', 'is_ignored' => true],
                    ['key' => 'peer_transfers', 'name' => 'Peer transfers'],
                ],
            ],
        ];
    }
}
