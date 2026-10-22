<?php

namespace App\Modules\Accounts\Models;

use App\Models\User;
use App\Support\Id\Uuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use one-time code. Only the SHA-256 hash of the code is stored.
 */
class OtpCode extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'channel',
        'destination',
        'purpose',
        'code_hash',
        'expires_at',
        'consumed_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OtpCode $code) {
            $code->id ??= Uuid7::generate();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
