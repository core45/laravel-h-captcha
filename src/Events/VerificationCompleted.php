<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Events;

use Core45\HCaptcha\Support\VerificationContext;
use Core45\HCaptcha\Support\VerificationResult;

/**
 * Fired once per hCaptcha verification that reached the service.
 *
 * Lets an application count outcomes, alert on configuration errors, or feed
 * its own metrics without turning on the database audit trail. A listener sees
 * every verdict, accepted or not.
 *
 * Nothing secret travels in the payload. The token is a single-use credential,
 * so only its SHA-256 hash is carried -- enough to correlate a replay across
 * two rows or two log lines, useless to anyone who intercepts the event. The
 * secret never appears at all. `$context` names the field, the action and the
 * credential profile, which is what makes a counter useful.
 *
 * Memoized verdicts do not re-fire it: one solved captcha checked by both the
 * middleware and the rule is one verification, and a listener that counted it
 * twice would over-report.
 *
 * Not fired for a submission carrying no token, or one over max_token_length:
 * neither reaches hCaptcha, and both are free for an unauthenticated caller to
 * trigger, so a listener that wrote a row or sent a metric per event would be
 * an amplifier. The `hcaptcha.logging.log_missing_token` and
 * `log_oversized_token` audit switches cover those deliberately.
 */
final readonly class VerificationCompleted
{
    public function __construct(
        public VerificationResult $result,
        public VerificationContext $context,
        /** SHA-256 of the token. Never the token itself. */
        public string $tokenHash,
    ) {}

    /**
     * Whether the package accepted the submission, after the local assertions
     * and the fail-open policy. Not the same as hCaptcha's own verdict, which
     * is `$result->success`.
     */
    public function passed(): bool
    {
        return $this->result->passed();
    }
}
