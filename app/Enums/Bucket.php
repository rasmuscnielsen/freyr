<?php

namespace App\Enums;

/**
 * The overview buckets a posting can land in. Not persisted: this is the
 * frontend contract for the dashboard, derived from a category's
 * {@see CategoryType} (and, for expenses, its {@see ExpenseGroup}).
 */
enum Bucket: string
{
    case Income = 'income';

    case Bills = 'bills';

    case LivingCosts = 'living_costs';

    case Lifestyle = 'lifestyle';

    case Other = 'other';

    case Investments = 'investments';

    case Savings = 'savings';

    case Uncategorized = 'uncategorized';

    /**
     * The single mapping from a category type (plus an optional expense group)
     * to an overview bucket. Returns null for {@see CategoryType::Exclude},
     * which is omitted from the overview entirely.
     */
    public static function forCategory(CategoryType $categoryType, ?ExpenseGroup $expenseGroup = null): ?self
    {
        return match ($categoryType) {
            CategoryType::Income => self::Income,
            CategoryType::Expense => match ($expenseGroup) {
                ExpenseGroup::Bills => self::Bills,
                ExpenseGroup::LivingCosts => self::LivingCosts,
                ExpenseGroup::Lifestyle => self::Lifestyle,
                ExpenseGroup::Other, null => self::Other,
            },
            CategoryType::Investment => self::Investments,
            CategoryType::Saving => self::Savings,
            CategoryType::Exclude => null,
        };
    }
}
