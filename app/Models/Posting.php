<?php

namespace App\Models;

use Database\Factories\PostingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Posting extends Model
{
    /** @use HasFactory<PostingFactory> */
    use HasFactory;

    public const int CATEGORY_TYPE_INCOME = 1;

    public const int CATEGORY_TYPE_EXPENSE = 2;

    public const int CATEGORY_TYPE_TRANSFER = 3;

    public const int CATEGORY_TYPE_SAVING = 4;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'bank_account_id',
        'subcategory_id',
        'categorized_subcategory_id',
        'counter_entry_posting_id',
        'legacy_id',
        'source',
        'counter_entry_legacy_id',
        'split_group_legacy_id',
        'date',
        'custom_date',
        'description',
        'description_embedding',
        'description_embedded_at',
        'original_description',
        'comment',
        'amount',
        'original_amount',
        'balance',
        'upload_batch_id',
        'external_posting_id',
        'external_account_uid_hash',
        'document_legacy_id',
        'is_extraordinary',
        'is_account_group_duplicate',
        'prevent_marking_as_counter_entry',
        'description_changed',
        'extra_data',
        'category_type_code',
        'posting_type',
        'posting_sub_type',
        'currency',
        'original_currency',
        'merchant_legacy_id',
        'country_code',
        'state',
        'carbon_emission_in_grams',
        'categorized_by',
        'legacy_created_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<Subcategory, $this>
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    /**
     * @return BelongsTo<Subcategory, $this>
     */
    public function categorizedSubcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class, 'categorized_subcategory_id');
    }

    /**
     * @return BelongsTo<Posting, $this>
     */
    public function counterEntryPosting(): BelongsTo
    {
        return $this->belongsTo(Posting::class, 'counter_entry_posting_id');
    }

    protected static function booted(): void
    {
        static::updating(function (Posting $posting): void {
            if (! $posting->isDirty('description')) {
                return;
            }

            $posting->description_embedding = null;
            $posting->description_embedded_at = null;
        });
    }

    /**
     * @param  Builder<Posting>  $query
     * @return Builder<Posting>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function countsAsOverviewIncome(): bool
    {
        return ! $this->is_extraordinary
            && $this->category_type_code === self::CATEGORY_TYPE_INCOME;
    }

    public function countsAsOverviewExpense(): bool
    {
        return ! $this->is_extraordinary
            && $this->category_type_code === self::CATEGORY_TYPE_EXPENSE;
    }

    public function countsAsOverviewSaving(): bool
    {
        return ! $this->is_extraordinary
            && $this->category_type_code === self::CATEGORY_TYPE_SAVING;
    }

    public function isFixedOverviewExpense(): bool
    {
        return $this->categorizedSubcategory?->expense_type === 'Fixed';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'custom_date' => 'date',
            'description_embedding' => 'array',
            'description_embedded_at' => 'datetime',
            'amount' => 'decimal:2',
            'original_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'is_extraordinary' => 'boolean',
            'is_account_group_duplicate' => 'boolean',
            'prevent_marking_as_counter_entry' => 'boolean',
            'description_changed' => 'boolean',
            'extra_data' => 'array',
            'legacy_created_at' => 'datetime',
        ];
    }
}
