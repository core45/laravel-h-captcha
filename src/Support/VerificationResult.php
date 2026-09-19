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
 * `success` is hCaptcha's own verdict and is never rewritten. `accepted` is
 * the package's final answer after the local hostname and score assertions
 * and the fail-open policy, and it is what `passed()` returns. A token can be
 * genuine (`success: true`) and still not accepted because it was minted for
 * another hostname; an outage can be accepted under fail-open without hCaptcha
 * ever having said yes.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class VerificationResult implements Arrayable, JsonSerializable
{
    /**
     * Codes hCaptcha documents for a token that was already checked.
     * `token-already-used` was this package's own 1.x name for the same
     * condition and is kept so fixtures written against it still work.
     *
     * @var list<string>
     */
    private const SPENT_TOKEN_CODES = [
        'already-seen-response',
        'invalid-or-already-seen-response',
        'token-already-used',
    ];

    /**
     * Codes that are the site owner's problem, not the visitor's.
     *
     * @var list<string>
     */
    private const CONFIGURATION_ERROR_CODES = [
        'missing-input-secret',
        'invalid-input-secret',
        'sitekey-secret-mismatch',
        'bad-request',
        'not-using-dummy-passcode',
        'not-using-dummy-secret',
    ];

    /**
     * Whether the package accepts the submission. Defaults to `success`.
     */
    public bool $accepted;

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
        ?bool $accepted = null,
    ) {
        $this->accepted = $accepted ?? $success;
    }

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
     * without being proxied to the service. `token-too-long` is this
     * package's own code: hCaptcha never saw the token, so none of its codes
     * apply.
     */
    public static function oversizedToken(): self
    {
        return new self(
            success: false,
            errorCodes: ['token-too-long'],
        );
    }

    /**
     * hCaptcha said yes, but a local assertion said no.
     *
     * `$reason` is one of `hostname-mismatch`, `hostname-unknown` or
     * `score-too-high`. `success` is preserved: it is hCaptcha's verdict, not
     * ours.
     */
    public function rejectedLocally(string $reason): self
    {
        return new self(
            success: $this->success,
            hostname: $this->hostname,
            challengeTs: $this->challengeTs,
            score: $this->score,
            scoreReasons: $this->scoreReasons,
            errorCodes: [...$this->errorCodes, $reason],
            credit: $this->credit,
            serviceUnavailable: $this->serviceUnavailable,
            rejectedBy: $reason,
            accepted: false,
        );
    }

    public function passed(): bool
    {
        return $this->accepted;
    }

    public function failed(): bool
    {
        return ! $this->accepted;
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
        return array_any(self::SPENT_TOKEN_CODES, fn (string $code): bool => $this->hasErrorCode($code));
    }

    /**
     * The token outlived its validity window before it was verified.
     */
    public function tokenExpired(): bool
    {
        return $this->hasErrorCode('expired-input-response');
    }

    /**
     * The token was not something hCaptcha could parse, or was rejected here
     * before being sent.
     */
    public function tokenMalformed(): bool
    {
        return $this->hasErrorCode('invalid-input-response')
            || $this->hasErrorCode('token-too-long');
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
        return array_any(self::CONFIGURATION_ERROR_CODES, fn (string $code): bool => $this->hasErrorCode($code));
    }

    /**
     * Translation key for the message a visitor should see.
     */
    public function messageKey(): string
    {
        return match (true) {
            $this->tokenMissing() => 'hcaptcha::hcaptcha.missing',
            $this->serviceUnavailable => 'hcaptcha::hcaptcha.unavailable',
            $this->tokenAlreadyUsed(), $this->tokenExpired() => 'hcaptcha::hcaptcha.expired',
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
            'accepted' => $this->accepted,
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
