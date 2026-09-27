<?php

namespace App\Models;

use Database\Factories\BankSyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankSyncRun extends Model
{
    /** @use HasFactory<BankSyncRunFactory> */
    use HasFactory;

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_SKIPPED = 'skipped';

    public const string GENERIC_ERROR_MESSAGE = 'Bank sync failed. Please try again.';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'bank_connection_id',
        'bank_connection_account_id',
        'bank_account_id',
        'status',
        'date_from',
        'date_to',
        'started_at',
        'finished_at',
        'new_postings_count',
        'updated_postings_count',
        'duplicate_postings_count',
        'expected_balance',
        'computed_balance',
        'balance_matches',
        'balance_match_basis',
        'error_code',
        'error_message',
        'raw_error',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'raw_error',
    ];

    /**
     * @return BelongsTo<BankConnection, $this>
     */
    public function bankConnection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class);
    }

    /**
     * @return BelongsTo<BankConnectionAccount, $this>
     */
    public function bankConnectionAccount(): BelongsTo
    {
        return $this->belongsTo(BankConnectionAccount::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * Whether the sync reconciled but the local figure disagreed with the bank.
     *
     * Returns false when reconciliation could not be performed (balance_matches null).
     */
    public function hasBalanceMismatch(): bool
    {
        return $this->balance_matches === false;
    }

    /**
     * Signed difference between the bank-reported and locally computed balance.
     *
     * Returns null when either side of the comparison is unavailable.
     */
    public function balanceDelta(): ?string
    {
        if ($this->expected_balance === null || $this->computed_balance === null) {
            return null;
        }

        return number_format((float) $this->expected_balance - (float) $this->computed_balance, 2, '.', '');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expected_balance' => 'decimal:2',
            'computed_balance' => 'decimal:2',
            'balance_matches' => 'boolean',
            'raw_error' => 'encrypted:array',
        ];
    }
}
