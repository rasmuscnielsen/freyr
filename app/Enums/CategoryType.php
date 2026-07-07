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
}
