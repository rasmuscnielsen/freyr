<?php

namespace App\Enums;

/**
 * The behavioral type of a category. Persisted on `categories.type` and the
 * single source of truth for how a categorized posting is bucketed in the
 * overview (see {@see Bucket}). Subcategories inherit their parent's type.
 */
enum CategoryType: string
{
    case Income = 'Income';

    case Expense = 'Expense';

    case Investment = 'Investment';

    case Saving = 'Saving';

    case Exclude = 'Exclude';

    /**
     * Display order for category pickers: everyday money flows first,
     * Exclude last. The enum's alphabetical order is meaningless to users.
     */
    public function displayOrder(): int
    {
        return match ($this) {
            self::Income => 0,
            self::Expense => 1,
            self::Saving => 2,
            self::Investment => 3,
            self::Exclude => 4,
        };
    }
}
