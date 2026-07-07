<?php

namespace App\Enums;

/**
 * Splits an expense subcategory into recurring bills versus discretionary
 * spending. Persisted on `subcategories.expense_type` and only meaningful
 * when the parent category type is {@see CategoryType::Expense}.
 */
enum ExpenseType: string
{
    case Fixed = 'Fixed';

    case Variable = 'Variable';
}
