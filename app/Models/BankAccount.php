<?php

namespace App\Models;

use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    public const string MANUAL_LEGACY_ID_PREFIX = 'manual-';

    public const string ENABLE_BANKING_LEGACY_ID_PREFIX = 'enable-banking-';

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
        'current_balance',
        'opening_balance',
        'account_type',
        'ignore_postings_before',
        'last_updated_at',
        'last_known_posting_date',
        'is_active',
        'account_number',
        'is_favorite',
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
     * @return HasMany<BankSyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(BankSyncRun::class);
    }

    /**
     * Whether the account is fed by a bank connection. Accounts without one
     * are maintained manually and allow posting create/edit/delete.
     */
    public function hasBankConnection(): bool
    {
        return $this->relationLoaded('bankConnectionAccounts')
            ? $this->bankConnectionAccounts->isNotEmpty()
            : $this->bankConnectionAccounts()->exists();
    }

    /**
     * Whether the running balance column should show. The newest posting
     * decides: the column only makes sense while the feed still checkpoints
     * the chain. Once the newest movement carries no provider balance — the
     * bank stopped sending `balance_after_transaction`, or manual postings
     * continue a detached account — older checkpoints are hidden rather than
     * shown as a permanently broken chain. A provider that starts sending
     * balances flips this back on with the next sync.
     */
    public function providesRunningBalance(): bool
    {
        return $this->postings()
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->value('balance') !== null;
    }

    /**
     * Accounts imported from a Spiir export carry their raw Spiir id, while
     * locally created accounts get a `manual-`/`enable-banking-` prefixed
     * legacy id. Only imported accounts may be linked to a live bank feed:
     * they are the ones holding migrated posting history worth syncing.
     */
    public function isSpiirImported(): bool
    {
        return ! Str::startsWith($this->legacy_id, [
            self::MANUAL_LEGACY_ID_PREFIX,
            self::ENABLE_BANKING_LEGACY_ID_PREFIX,
        ]);
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
            'last_updated_at' => 'datetime',
            'last_known_posting_date' => 'date',
            'is_active' => 'boolean',
            'is_favorite' => 'boolean',
            'current_balance' => 'decimal:2',
            'opening_balance' => 'decimal:2',
        ];
    }
}
