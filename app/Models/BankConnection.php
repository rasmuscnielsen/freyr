<?php

namespace App\Models;

use Database\Factories\BankConnectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankConnection extends Model
{
    /** @use HasFactory<BankConnectionFactory> */
    use HasFactory, Prunable;

    public const string PROVIDER_ENABLE_BANKING = 'enable_banking';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_RENEWAL_REQUIRED = 'renewal_required';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_REVOKED = 'revoked';

    public const string STATUS_ARCHIVED = 'archived';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'status',
        'aspsp_name',
        'aspsp_country',
        'psu_type',
        'authorization_id',
        'state',
        'session_id',
        'session_id_hash',
        'authorized_at',
        'access_valid_until',
        'last_successful_sync_at',
        'last_failed_sync_at',
        'failure_code',
        'failure_message',
        'raw_session_payload',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'session_id',
        'state',
        'raw_session_payload',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BankConnectionAccount, $this>
     */
    public function accounts(): HasMany
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

    public function needsRenewal(): bool
    {
        return $this->status === self::STATUS_RENEWAL_REQUIRED
            || ($this->access_valid_until !== null && $this->access_valid_until->isPast());
    }

    /**
     * Pending authorizations are only useful while their consent flow can
     * still complete; after two days the row is an abandoned corpse.
     *
     * @return Builder<BankConnection>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('status', self::STATUS_PENDING)
            ->where('created_at', '<', now()->subDays(2));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'session_id' => 'encrypted',
            'authorized_at' => 'datetime',
            'access_valid_until' => 'datetime',
            'last_successful_sync_at' => 'datetime',
            'last_failed_sync_at' => 'datetime',
            'raw_session_payload' => 'encrypted:array',
        ];
    }
}
