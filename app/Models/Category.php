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

    protected static function booted(): void
    {
        // Subcategories cascade at the database level, nulling posting FKs
        // without model events; see Subcategory::booted().
        static::deleting(fn (Category $category) => Posting::touchForSubcategories($category->subcategories()->pluck('id')->all()));
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
