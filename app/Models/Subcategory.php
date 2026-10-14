<?php

namespace App\Models;

use App\Enums\ExpenseGroup;
use Database\Factories\SubcategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subcategory extends Model
{
    /** @use HasFactory<SubcategoryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // The postings FKs null on delete without touching the rows; bump
        // `updated_at` first so API `updated_since` sync sees the change.
        static::deleting(fn (Subcategory $subcategory) => Posting::touchForSubcategories([$subcategory->id]));
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'legacy_id',
        'name',
        'hyphenated_name',
        'expense_group',
        'hints',
    ];

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<Posting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class, 'categorized_subcategory_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expense_group' => ExpenseGroup::class,
        ];
    }
}
