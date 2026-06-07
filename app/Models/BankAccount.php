<?php

namespace App\Models;

use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'legacy_id',
        'name',
        'bank_key',
        'bank_name',
        'currency',
        'last_booked_balance',
        'last_available_balance',
        'account_type',
        'account_subcategory_key',
        'account_group_legacy_id',
        'ignore_postings_before',
        'ignore_postings_after',
        'last_updated_at',
        'last_known_posting_date',
        'is_active',
        'inactivated_by',
        'account_number',
        'owner_name',
        'supports_payment_from',
        'supports_payment_to',
        'is_favorite',
        'connection_type',
        'donation_topup_count',
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
     * @return HasMany<Posting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class);
    }

    /**
     * @return HasMany<BankConnectionAccount, $this>
     */
    public function bankConnectionAccounts(): HasMany
    {
        return $this->hasMany(BankConnectionAccount::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ignore_postings_before' => 'date',
            'ignore_postings_after' => 'date',
            'last_updated_at' => 'datetime',
            'last_known_posting_date' => 'date',
            'is_active' => 'boolean',
            'supports_payment_from' => 'boolean',
            'supports_payment_to' => 'boolean',
            'is_favorite' => 'boolean',
            'last_booked_balance' => 'decimal:2',
            'last_available_balance' => 'decimal:2',
            'legacy_created_at' => 'datetime',
        ];
    }
}
