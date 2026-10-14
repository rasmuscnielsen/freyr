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

    /** @var list<int> */
    private array $referencingPostingIds = [];

    protected static function booted(): void
    {
        // Deleting nulls posting FKs in the database without touching the
        // rows; bump their `updated_at` afterwards for `changed_since` sync.
        static::deleting(function (Subcategory $model): void {
            $model->referencingPostingIds = Posting::idsReferencingSubcategories([$model->id]);
        });
        static::deleted(fn (Subcategory $model) => Posting::touchByIds($model->referencingPostingIds));
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
