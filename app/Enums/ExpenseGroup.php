<?php

namespace App\Enums;

/**
 * Reporting grouping for expense subcategories. Null means unassigned.
 */
enum ExpenseGroup: string
{
    case Bills = 'Bills';
    case LivingCosts = 'Living costs';
    case Lifestyle = 'Lifestyle';
    case Other = 'Other';
}
