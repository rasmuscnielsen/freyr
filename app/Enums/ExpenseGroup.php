<?php

namespace App\Enums;

/**
 * Reporting grouping for expense subcategories. Persisted on
 * `subcategories.expense_group` using the case name, like {@see CategoryType};
 * the display {@see label()} can change without touching stored data. Null
 * means unassigned.
 */
enum ExpenseGroup: string
{
    case Bills = 'Bills';

    case LivingCosts = 'LivingCosts';

    case Lifestyle = 'Lifestyle';

    case Other = 'Other';

    /**
     * Human-readable label for pickers and badges.
     */
    public function label(): string
    {
        return match ($this) {
            self::Bills => 'Bills',
            self::LivingCosts => 'Living costs',
            self::Lifestyle => 'Lifestyle',
            self::Other => 'Other',
        };
    }
}
