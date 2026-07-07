<?php

namespace Database\Seeders;

use App\Enums\CategoryType;
use App\Enums\ExpenseType;
use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a sensible default category tree for new installations. Safe to run
 * in production and idempotent: rows are keyed by legacy_id and created only
 * when missing (firstOrCreate), so re-running never duplicates and never
 * clobbers admin edits. Spiir imports are unaffected (they use their own
 * legacy ids).
 */
class DefaultCategoriesSeeder extends Seeder
{
    use WithoutModelEvents;

    public const string CATEGORY_LEGACY_ID_PREFIX = 'default-category-';

    public const string SUBCATEGORY_LEGACY_ID_PREFIX = 'default-subcategory-';

    public function run(): void
    {
        foreach ($this->categoryDefinitions() as $categoryDefinition) {
            $category = Category::query()->firstOrCreate(
                ['legacy_id' => self::CATEGORY_LEGACY_ID_PREFIX.$categoryDefinition['key']],
                [
                    'name' => $categoryDefinition['name'],
                    'type' => $categoryDefinition['type']->value,
                    'hyphenated_name' => Str::slug($categoryDefinition['name']),
                ],
            );

            foreach ($categoryDefinition['subcategories'] as $subcategoryDefinition) {
                Subcategory::query()->firstOrCreate(
                    ['legacy_id' => self::SUBCATEGORY_LEGACY_ID_PREFIX.$subcategoryDefinition['key']],
                    [
                        'category_id' => $category->id,
                        'name' => $subcategoryDefinition['name'],
                        'hyphenated_name' => Str::slug($subcategoryDefinition['name']),
                        'expense_type' => ($subcategoryDefinition['expense_type'] ?? null)?->value,
                        'hints' => null,
                    ],
                );
            }
        }
    }

    /**
     * @return list<array{key:string,name:string,type:CategoryType,subcategories:list<array{key:string,name:string,expense_type?:ExpenseType|null}>}>
     */
    private function categoryDefinitions(): array
    {
        return [
            [
                'key' => 'income',
                'name' => 'Income',
                'type' => CategoryType::Income,
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
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'rent', 'name' => 'Rent', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'mortgage', 'name' => 'Mortgage', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'utilities', 'name' => 'Utilities', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'internet', 'name' => 'Internet', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'phone', 'name' => 'Phone', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'insurance', 'name' => 'Insurance', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'home', 'name' => 'Home & garden', 'expense_type' => ExpenseType::Variable],
                ],
            ],
            [
                'key' => 'food',
                'name' => 'Food & groceries',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'groceries', 'name' => 'Groceries', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'restaurants', 'name' => 'Restaurants', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'takeaway', 'name' => 'Takeaway', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'coffee', 'name' => 'Coffee & snacks', 'expense_type' => ExpenseType::Variable],
                ],
            ],
            [
                'key' => 'transport',
                'name' => 'Transport',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'transport', 'name' => 'Public transport', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'fuel', 'name' => 'Fuel', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'car', 'name' => 'Car & maintenance', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'car_fixed', 'name' => 'Car insurance & tax', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'taxi', 'name' => 'Taxi & ride sharing', 'expense_type' => ExpenseType::Variable],
                ],
            ],
            [
                'key' => 'personal',
                'name' => 'Personal',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'clothing', 'name' => 'Clothing', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'personal_care', 'name' => 'Personal care', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'fitness', 'name' => 'Fitness & sports', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'subscriptions', 'name' => 'Subscriptions & memberships', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'electronics', 'name' => 'Electronics', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'gifts_given', 'name' => 'Gifts & charity', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'education', 'name' => 'Education', 'expense_type' => ExpenseType::Variable],
                ],
            ],
            [
                'key' => 'leisure',
                'name' => 'Leisure',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'streaming', 'name' => 'Streaming', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'entertainment', 'name' => 'Entertainment & culture', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'hobbies', 'name' => 'Hobbies', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'travel', 'name' => 'Travel', 'expense_type' => ExpenseType::Variable],
                ],
            ],
            [
                'key' => 'health',
                'name' => 'Health',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'pharmacy', 'name' => 'Pharmacy', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'doctor', 'name' => 'Doctor & dentist', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'glasses', 'name' => 'Glasses & contacts', 'expense_type' => ExpenseType::Variable],
                ],
            ],
            [
                'key' => 'family',
                'name' => 'Family & children',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'childcare', 'name' => 'Childcare', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'kids', 'name' => 'Kids activities & gear', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'pets', 'name' => 'Pets', 'expense_type' => ExpenseType::Variable],
                ],
            ],
            [
                'key' => 'financial',
                'name' => 'Financial',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'bank_fees', 'name' => 'Bank fees', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'loan_payments', 'name' => 'Loan payments', 'expense_type' => ExpenseType::Fixed],
                    ['key' => 'other_expenses', 'name' => 'Other expenses', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'cash_withdrawal', 'name' => 'Cash withdrawal', 'expense_type' => ExpenseType::Variable],
                    ['key' => 'peer_transfers', 'name' => 'Peer transfers', 'expense_type' => ExpenseType::Variable],
                ],
            ],
            [
                'key' => 'saving',
                'name' => 'Saving',
                'type' => CategoryType::Saving,
                'subcategories' => [
                    ['key' => 'emergency_fund', 'name' => 'Emergency fund'],
                    ['key' => 'pension_savings', 'name' => 'Pension savings'],
                ],
            ],
            [
                'key' => 'investments',
                'name' => 'Investments',
                'type' => CategoryType::Investment,
                'subcategories' => [
                    ['key' => 'investments', 'name' => 'Investments'],
                ],
            ],
            [
                'key' => 'transfer',
                'name' => 'Transfers',
                'type' => CategoryType::Exclude,
                'subcategories' => [
                    ['key' => 'internal_transfer', 'name' => 'Internal transfer'],
                    ['key' => 'credit_card_payment', 'name' => 'Credit card payment'],
                ],
            ],
        ];
    }
}
