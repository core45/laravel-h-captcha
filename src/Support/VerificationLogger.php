<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Support;

use Core45\HCaptcha\Models\HCaptchaVerification;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one audit row per verification attempt.
 *
 * The raw token never reaches the database. It is a single-use credential, and
 * a stored copy would be both useless (it is already spent) and a liability.
 * Only its SHA-256 hash is kept, which is enough to correlate a replay.
 *
 * A failure to write the audit row must never fail the verification itself, so
 * every write is guarded.
 */
final readonly class VerificationLogger
{
    public function __construct(
        private Repository $config,
        private Request $request,
        private LoggerInterface $logger,
    ) {}

    public function record(VerificationResult $result, ?string $tokenHash, ?string $clientIp): void
    {
        if (! $this->config->get('hcaptcha.logging.enabled', false)) {
            return;
        }

        try {
            HCaptchaVerification::query()->create($this->row($result, $tokenHash, $clientIp));
        } catch (Throwable $exception) {
            $this->logger->warning('hCaptcha audit row could not be written.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(VerificationResult $result, ?string $tokenHash, ?string $clientIp): array
    {
        return [
            'success' => $result->success,
            'token_hash' => $tokenHash,
            'hostname' => $result->hostname,
            'challenge_ts' => $result->challengeTs,
            'score' => $result->score,
            'error_codes' => $result->errorCodes === [] ? null : $result->errorCodes,
            'rejected_by' => $result->rejectedBy,
            'ip' => $this->config->get('hcaptcha.logging.store_ip', false) ? $clientIp : null,
            'user_agent' => $this->config->get('hcaptcha.logging.store_user_agent', false)
                ? $this->truncate($this->request->userAgent(), 512)
                : null,
            // url(), not fullUrl(): a query string routinely carries
            // signed-URL signatures, password-reset tokens and email
            // addresses, none of which belong in a 90-day audit table.
            // The fallback is false so an older published config that omits
            // the key cannot silently re-enable this.
            'url' => $this->config->get('hcaptcha.logging.store_url', false)
                ? $this->truncate($this->request->url(), 2048)
                : null,
        ];
    }

    private function truncate(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
