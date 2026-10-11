<?php

namespace Database\Seeders;

use App\Enums\CategoryType;
use App\Enums\ExpenseGroup;
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
                        'expense_group' => ($subcategoryDefinition['expense_group'] ?? null)?->value,
                        'hints' => null,
                    ],
                );
            }
        }
    }

    /**
     * @return list<array{key:string,name:string,type:CategoryType,subcategories:list<array{key:string,name:string,expense_group?:ExpenseGroup|null}>}>
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
                    ['key' => 'rent', 'name' => 'Rent', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'mortgage', 'name' => 'Mortgage', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'utilities', 'name' => 'Utilities', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'internet', 'name' => 'Internet', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'phone', 'name' => 'Phone', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'insurance', 'name' => 'Insurance', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'home', 'name' => 'Home & garden', 'expense_group' => ExpenseGroup::LivingCosts],
                ],
            ],
            [
                'key' => 'food',
                'name' => 'Food & groceries',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'groceries', 'name' => 'Groceries', 'expense_group' => ExpenseGroup::LivingCosts],
                    ['key' => 'restaurants', 'name' => 'Restaurants', 'expense_group' => ExpenseGroup::Lifestyle],
                    ['key' => 'takeaway', 'name' => 'Takeaway', 'expense_group' => ExpenseGroup::Lifestyle],
                    ['key' => 'coffee', 'name' => 'Coffee & snacks', 'expense_group' => ExpenseGroup::Lifestyle],
                ],
            ],
            [
                'key' => 'transport',
                'name' => 'Transport',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'transport', 'name' => 'Public transport', 'expense_group' => ExpenseGroup::LivingCosts],
                    ['key' => 'fuel', 'name' => 'Fuel', 'expense_group' => ExpenseGroup::LivingCosts],
                    ['key' => 'car', 'name' => 'Car & maintenance', 'expense_group' => ExpenseGroup::LivingCosts],
                    ['key' => 'car_fixed', 'name' => 'Car insurance & tax', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'taxi', 'name' => 'Taxi & ride sharing', 'expense_group' => ExpenseGroup::Lifestyle],
                ],
            ],
            [
                'key' => 'personal',
                'name' => 'Personal',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'clothing', 'name' => 'Clothing', 'expense_group' => ExpenseGroup::LivingCosts],
                    ['key' => 'personal_care', 'name' => 'Personal care', 'expense_group' => ExpenseGroup::LivingCosts],
                    ['key' => 'fitness', 'name' => 'Fitness & sports', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'subscriptions', 'name' => 'Subscriptions & memberships', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'electronics', 'name' => 'Electronics', 'expense_group' => ExpenseGroup::Lifestyle],
                    ['key' => 'gifts_given', 'name' => 'Gifts & charity', 'expense_group' => ExpenseGroup::Lifestyle],
                    ['key' => 'education', 'name' => 'Education', 'expense_group' => ExpenseGroup::LivingCosts],
                ],
            ],
            [
                'key' => 'leisure',
                'name' => 'Leisure',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'streaming', 'name' => 'Streaming', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'entertainment', 'name' => 'Entertainment & culture', 'expense_group' => ExpenseGroup::Lifestyle],
                    ['key' => 'hobbies', 'name' => 'Hobbies', 'expense_group' => ExpenseGroup::Lifestyle],
                    ['key' => 'travel', 'name' => 'Travel', 'expense_group' => ExpenseGroup::Lifestyle],
                ],
            ],
            [
                'key' => 'health',
                'name' => 'Health',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'pharmacy', 'name' => 'Pharmacy', 'expense_group' => ExpenseGroup::LivingCosts],
                    ['key' => 'doctor', 'name' => 'Doctor & dentist', 'expense_group' => ExpenseGroup::LivingCosts],
                    ['key' => 'glasses', 'name' => 'Glasses & contacts', 'expense_group' => ExpenseGroup::LivingCosts],
                ],
            ],
            [
                'key' => 'family',
                'name' => 'Family & children',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'childcare', 'name' => 'Childcare', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'kids', 'name' => 'Kids activities & gear', 'expense_group' => ExpenseGroup::LivingCosts],
                    ['key' => 'pets', 'name' => 'Pets', 'expense_group' => ExpenseGroup::LivingCosts],
                ],
            ],
            [
                'key' => 'financial',
                'name' => 'Financial',
                'type' => CategoryType::Expense,
                'subcategories' => [
                    ['key' => 'bank_fees', 'name' => 'Bank fees', 'expense_group' => ExpenseGroup::Other],
                    ['key' => 'loan_payments', 'name' => 'Loan payments', 'expense_group' => ExpenseGroup::Bills],
                    ['key' => 'other_expenses', 'name' => 'Other expenses', 'expense_group' => ExpenseGroup::Other],
                    ['key' => 'cash_withdrawal', 'name' => 'Cash withdrawal', 'expense_group' => ExpenseGroup::Other],
                    ['key' => 'peer_transfers', 'name' => 'Peer transfers', 'expense_group' => ExpenseGroup::Other],
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
