<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;
use Throwable;

/**
 * The outcome of one hCaptcha siteverify call.
 *
 * `success` is hCaptcha's own verdict. It is deliberately kept separate from
 * `passed()`, which additionally applies the local hostname and score
 * assertions -- a token can be genuine and still be rejected because it was
 * minted for another hostname.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class VerificationResult implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $errorCodes
     * @param  list<string>  $scoreReasons
     */
    public function __construct(
        public bool $success,
        public ?string $hostname = null,
        public ?Carbon $challengeTs = null,
        public ?float $score = null,
        public array $scoreReasons = [],
        public array $errorCodes = [],
        public ?bool $credit = null,
        public bool $serviceUnavailable = false,
        public ?string $rejectedBy = null,
    ) {}

    /**
     * Build a result from a decoded siteverify payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponse(array $payload): self
    {
        return new self(
            // Identity, not a cast. `(bool) "false"`, `(bool) "error"` and
            // `(bool) -1` are all true, so a malformed 200 response would turn
            // a rejection into a pass in the one control whose entire job is to
            // fail closed.
            success: ($payload['success'] ?? null) === true,
            hostname: self::stringOrNull($payload['hostname'] ?? null),
            challengeTs: self::timestampOrNull($payload['challenge_ts'] ?? null),
            score: isset($payload['score']) && is_numeric($payload['score'])
                ? (float) $payload['score']
                : null,
            scoreReasons: self::stringList($payload['score_reason'] ?? []),
            errorCodes: self::stringList($payload['error-codes'] ?? $payload['error_codes'] ?? []),
            credit: isset($payload['credit']) ? (bool) $payload['credit'] : null,
        );
    }

    /**
     * hCaptcha could not be reached, or answered with something unusable.
     */
    public static function unavailable(): self
    {
        return new self(
            success: false,
            errorCodes: ['service-unavailable'],
            serviceUnavailable: true,
        );
    }

    /**
     * The form arrived with no token at all, so no HTTP call was made.
     */
    public static function missingToken(): self
    {
        return new self(
            success: false,
            errorCodes: ['missing-input-response'],
        );
    }

    /**
     * The token was longer than any real hCaptcha token, so it was rejected
     * without being proxied to the service.
     */
    public static function oversizedToken(): self
    {
        return new self(
            success: false,
            errorCodes: ['invalid-input-response'],
        );
    }

    /**
     * hCaptcha said yes, but a local assertion said no.
     *
     * `$reason` is one of `hostname-mismatch` or `score-too-high`.
     */
    public function rejectedLocally(string $reason): self
    {
        return new self(
            success: false,
            hostname: $this->hostname,
            challengeTs: $this->challengeTs,
            score: $this->score,
            scoreReasons: $this->scoreReasons,
            errorCodes: [...$this->errorCodes, $reason],
            credit: $this->credit,
            serviceUnavailable: $this->serviceUnavailable,
            rejectedBy: $reason,
        );
    }

    public function passed(): bool
    {
        return $this->success;
    }

    public function failed(): bool
    {
        return ! $this->success;
    }

    public function hasErrorCode(string $code): bool
    {
        return in_array($code, $this->errorCodes, true);
    }

    /**
     * The token had already been spent. hCaptcha tokens are single-use, so this
     * usually means the form was double-submitted or the widget was not reset.
     */
    public function tokenAlreadyUsed(): bool
    {
        return $this->hasErrorCode('token-already-used')
            || $this->hasErrorCode('invalid-input-response');
    }

    public function tokenMissing(): bool
    {
        return $this->hasErrorCode('missing-input-response');
    }

    /**
     * Whether the failure is the site owner's fault rather than the visitor's.
     * These deserve a log entry, not just a validation message.
     */
    public function isConfigurationError(): bool
    {
        return array_any(['missing-input-secret', 'invalid-input-secret', 'bad-secret', 'no-such-user', 'invalid-sitekey', 'sitekey-mismatch'], fn (string $code): bool => $this->hasErrorCode($code));
    }

    /**
     * Translation key for the message a visitor should see.
     */
    public function messageKey(): string
    {
        return match (true) {
            $this->tokenMissing() => 'hcaptcha::hcaptcha.missing',
            $this->serviceUnavailable => 'hcaptcha::hcaptcha.unavailable',
            $this->tokenAlreadyUsed() => 'hcaptcha::hcaptcha.expired',
            default => 'hcaptcha::hcaptcha.failed',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'hostname' => $this->hostname,
            'challenge_ts' => $this->challengeTs?->toIso8601String(),
            'score' => $this->score,
            'score_reasons' => $this->scoreReasons,
            'error_codes' => $this->errorCodes,
            'credit' => $this->credit,
            'service_unavailable' => $this->serviceUnavailable,
            'rejected_by' => $this->rejectedBy,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function timestampOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (string|int|float|bool $item): string => (string) $item,
            array_filter($value, is_scalar(...)),
        ));
    }
}
