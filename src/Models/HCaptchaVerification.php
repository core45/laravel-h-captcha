<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One recorded hCaptcha verification attempt.
 *
 * @property int $id
 * @property bool $success
 * @property string|null $token_hash
 * @property string|null $hostname
 * @property Carbon|null $challenge_ts
 * @property float|null $score
 * @property list<string>|null $error_codes
 * @property string|null $rejected_by
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $url
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class HCaptchaVerification extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'success',
        'token_hash',
        'hostname',
        'challenge_ts',
        'score',
        'error_codes',
        'rejected_by',
        'ip',
        'user_agent',
        'url',
    ];

    public function getTable(): string
    {
        return (string) config('hcaptcha.logging.table', 'hcaptcha_verifications');
    }

    public function getConnectionName(): ?string
    {
        $connection = config('hcaptcha.logging.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    /**
     * Attempts hCaptcha itself rejected, or a local assertion rejected.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('success', false);
    }

    /**
     * Attempts sharing a token hash -- the fingerprint of a replayed token.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForToken(Builder $query, string $tokenHash): Builder
    {
        return $query->where('token_hash', $tokenHash);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'challenge_ts' => 'datetime',
            'score' => 'float',
            'error_codes' => 'array',
        ];
    }
}
