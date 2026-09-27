<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A cached provider exchange rate: `rate` quote-currency units per one
 * base-currency unit, valid for `effective_date`. Shared across users.
 */
class ExchangeRate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'base_currency',
        'quote_currency',
        'effective_date',
        'rate',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'rate' => 'decimal:10',
        ];
    }
}
