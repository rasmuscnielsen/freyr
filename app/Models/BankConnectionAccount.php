<?php

namespace App\Models;

use Database\Factories\BankConnectionAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankConnectionAccount extends Model
{
    /** @use HasFactory<BankConnectionAccountFactory> */
    use HasFactory;

    public const string STATUS_UNLINKED = 'unlinked';

    public const string STATUS_LINKED = 'linked';

    public const string STATUS_IGNORED = 'ignored';

    public const string STATUS_RENEWAL_REQUIRED = 'renewal_required';

    public const string SOURCE_ENABLE_BANKING = 'enable_banking';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'bank_connection_id',
        'bank_account_id',
        'status',
        'external_account_uid',
        'external_account_uid_hash',
        'identification_hash',
        'identification_hashes',
        'name',
        'details',
        'currency',
        'iban',
        'account_number',
        'account_number_last_four',
        'bic',
        'product',
        'cash_account_type',
        'raw_account_payload',
        'last_synced_at',
        'last_transaction_date',
        'provides_running_balance',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'external_account_uid',
        'identification_hash',
        'identification_hashes',
        'iban',
        'account_number',
        'raw_account_payload',
    ];

    /**
     * @return BelongsTo<BankConnection, $this>
     */
    public function bankConnection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return HasMany<BankSyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(BankSyncRun::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'external_account_uid' => 'encrypted',
            'identification_hashes' => 'encrypted:array',
            'iban' => 'encrypted',
            'account_number' => 'encrypted',
            'raw_account_payload' => 'encrypted:array',
            'last_synced_at' => 'datetime',
            'last_transaction_date' => 'date',
            'provides_running_balance' => 'boolean',
        ];
    }
}
