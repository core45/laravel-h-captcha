<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Support;

use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Exceptions\MissingSecretException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Verifies tokens against https://api.hcaptcha.com/siteverify using Laravel's
 * HTTP client, and memoizes the verdict for the rest of the request.
 *
 * The memoization is not an optimisation. hCaptcha tokens are single-use, and
 * a form guarded by both the validation rule and the middleware -- or a
 * Filament field that validates on update and again on submit -- would spend
 * the token on the first call and be told `token-already-used` on the second.
 */
final class HttpVerifier implements Verifier
{
    /**
     * Verdicts already obtained this request, keyed by token hash.
     *
     * @var array<string, VerificationResult>
     */
    private array $memo = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
        private readonly VerificationLogger $audit,
    ) {}

    public function verify(?string $token, ?string $clientIp = null, ?string $scope = null): VerificationResult
    {
        if ($token === null || trim($token) === '') {
            $result = VerificationResult::missingToken();

            // Recording this is opt-in: it needs no HTTP call, so an empty POST
            // is a free audit row for an attacker.
            if ($this->config->get('hcaptcha.logging.log_missing_token', false)) {
                $this->audit->record($result, null, $clientIp);
            }

            return $result;
        }

        // strlen, not mb_strlen: this is a transport-size guard, so bytes on
        // the wire are what matter, not characters.
        if (strlen($token) > $this->maxTokenLength()) {
            $this->logger->warning('hCaptcha token exceeded the configured maximum length and was rejected without a request.', [
                'bytes' => strlen($token),
            ]);

            return VerificationResult::oversizedToken();
        }

        // Keyed on scope as well as token: see the Verifier contract. An
        // unscoped memo lets one solved captcha authorise every component in a
        // Livewire batch.
        $tokenHash = hash('sha256', $token);

        // No scope means no memoization. Sharing one anonymous bucket would let
        // a pass obtained for one action be reused by an unrelated one, so an
        // unscoped caller instead pays a real request and hCaptcha answers
        // `token-already-used` on the second attempt -- which is correct for a
        // single-use token.
        $key = $scope === null || $scope === ''
            ? null
            : $tokenHash.'|'.$scope;

        if ($key !== null && array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $result = $this->assert($this->call($token, $clientIp));

        // The audit row records the token hash alone. The scope is a memo
        // concern; mixing it in would break replay correlation across scopes.
        $this->audit->record($result, $tokenHash, $clientIp);
        $this->report($result);

        if ($key === null) {
            return $result;
        }

        return $this->memo[$key] = $result;
    }

    private function maxTokenLength(): int
    {
        $configured = (int) $this->config->get('hcaptcha.max_token_length', 8192);

        return $configured > 0 ? $configured : 8192;
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * Perform the siteverify request, converting any transport failure into an
     * unavailable verdict rather than letting it escape into the form handler.
     */
    private function call(string $token, ?string $clientIp): VerificationResult
    {
        $secret = $this->config->get('hcaptcha.secret');

        if (! is_string($secret) || trim($secret) === '') {
            throw MissingSecretException::make();
        }

        $payload = [
            'secret' => $secret,
            'response' => $token,
        ];

        if ($clientIp !== null && $clientIp !== '') {
            $payload['remoteip'] = $clientIp;
        }

        $sitekey = $this->config->get('hcaptcha.sitekey');

        if ($this->config->get('hcaptcha.send_sitekey', true) && is_string($sitekey) && $sitekey !== '') {
            $payload['sitekey'] = $sitekey;
        }

        try {
            $response = $this->http
                ->asForm()
                ->timeout(max(1, (int) $this->config->get('hcaptcha.timeout', 10)))
                ->retry(max(1, (int) $this->config->get('hcaptcha.retries', 1)), 150, throw: false)
                ->post(
                    (string) $this->config->get('hcaptcha.endpoint', 'https://api.hcaptcha.com/siteverify'),
                    $payload,
                );
        } catch (Throwable $exception) {
            $this->logger->warning('hCaptcha verification could not reach the service.', [
                'exception' => $exception->getMessage(),
            ]);

            return $this->unavailable();
        }

        if ($response->failed()) {
            $this->logger->warning('hCaptcha verification returned an error status.', [
                'status' => $response->status(),
            ]);

            return $this->unavailable();
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $this->logger->warning('hCaptcha verification returned an unreadable body.');

            return $this->unavailable();
        }

        /** @var array<string, mixed> $payload */
        return VerificationResult::fromResponse($payload);
    }

    /**
     * Apply the local assertions hCaptcha cannot make for us.
     */
    private function assert(VerificationResult $result): VerificationResult
    {
        if ($result->failed()) {
            return $result;
        }

        $hostnames = $this->allowedHostnames();

        if ($hostnames !== [] && ! in_array(mb_strtolower((string) $result->hostname), $hostnames, true)) {
            return $result->rejectedLocally('hostname-mismatch');
        }

        $maxScore = $this->config->get('hcaptcha.max_score');

        if (is_numeric($maxScore) && $result->score !== null && $result->score > (float) $maxScore) {
            return $result->rejectedLocally('score-too-high');
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function allowedHostnames(): array
    {
        $configured = $this->config->get('hcaptcha.hostnames');

        // A string may hold a comma-separated list, because it usually arrives
        // from HCAPTCHA_HOSTNAMES and an env var cannot carry an array. Without
        // splitting, "a.test,b.test" became one literal that no real hostname
        // could ever match.
        $hostnames = match (true) {
            is_array($configured) => $configured,
            is_string($configured) => explode(',', $configured),
            default => [$configured],
        };

        $usable = array_values(array_filter(
            array_map(
                static fn (string|int|float|bool $hostname): string => mb_strtolower(trim((string) $hostname)),
                array_filter($hostnames, is_scalar(...)),
            ),
            // Blank entries are scalars, so without this an empty string would
            // survive as a "restriction" that no real hostname can ever match.
            static fn (string $hostname): bool => $hostname !== '',
        ));

        // Any route to an empty list disables the check, including an unset
        // APP_URL or a bare host with no scheme (parse_url returns null for
        // those). It must never be silent: this is the only defence against a
        // token solved elsewhere with our own public sitekey.
        if ($usable === []) {
            $this->logger->error(
                'hCaptcha hostname check is inactive: hcaptcha.hostnames resolved to nothing usable. '
                .'Set HCAPTCHA_HOSTNAMES, or an APP_URL that includes a scheme.'
            );
        }

        return $usable;
    }

    /**
     * An outage fails closed unless the application has opted into fail-open.
     */
    private function unavailable(): VerificationResult
    {
        if ($this->config->get('hcaptcha.fail_open', false)) {
            return new VerificationResult(
                success: true,
                errorCodes: ['service-unavailable'],
                serviceUnavailable: true,
            );
        }

        return VerificationResult::unavailable();
    }

    /**
     * Surface the failures that are the site owner's problem, not the visitor's.
     */
    private function report(VerificationResult $result): void
    {
        if ($result->isConfigurationError()) {
            $this->logger->error('hCaptcha rejected the request because of a configuration problem.', [
                'error_codes' => $result->errorCodes,
            ]);

            return;
        }

        if ($result->rejectedBy !== null) {
            $this->logger->warning('hCaptcha token was genuine but rejected by a local assertion.', [
                'rejected_by' => $result->rejectedBy,
                'hostname' => $result->hostname,
                'score' => $result->score,
            ]);
        }
    }
}
