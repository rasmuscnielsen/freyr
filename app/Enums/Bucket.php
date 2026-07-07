<?php

namespace App\Enums;

/**
 * The overview buckets a posting can land in. Not persisted: this is the
 * frontend contract for the dashboard, derived from a category's
 * {@see CategoryType} (and, for expenses, its {@see ExpenseType}).
 */
enum Bucket: string
{
    case Income = 'income';

    case Bills = 'bills';

    case Consumption = 'consumption';

    case Investments = 'investments';

    case Savings = 'savings';

    case Uncategorized = 'uncategorized';

    /**
     * The single mapping from a category type (plus an optional expense type)
     * to an overview bucket. Returns null for {@see CategoryType::Exclude},
     * which is omitted from the overview entirely.
     */
    public static function forCategory(CategoryType $categoryType, ?ExpenseType $expenseType = null): ?self
    {
        return match ($categoryType) {
            CategoryType::Income => self::Income,
            CategoryType::Expense => $expenseType === ExpenseType::Fixed
                ? self::Bills
                : self::Consumption,
            CategoryType::Investment => self::Investments,
            CategoryType::Saving => self::Savings,
            CategoryType::Exclude => null,
        };
    }
}
