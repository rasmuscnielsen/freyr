<?php

namespace App\Models;

use App\Enums\CategoryType;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** @var list<int> */
    private array $referencingPostingIds = [];

    protected static function booted(): void
    {
        // Deleting nulls posting FKs in the database without touching the
        // rows; bump their `updated_at` afterwards for `updated_since` sync.
        static::deleting(function (Category $model): void {
            $model->referencingPostingIds = Posting::idsReferencingSubcategories($model->subcategories()->pluck('id')->all());
        });
        static::deleted(fn (Category $model) => Posting::touchByIds($model->referencingPostingIds));
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'legacy_id',
        'name',
        'type',
        'hyphenated_name',
    ];

    /**
     * @return HasMany<Subcategory, $this>
     */
    public function subcategories(): HasMany
    {
        return $this->hasMany(Subcategory::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
        ];
    }
}
