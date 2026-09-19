<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Support;

use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Exceptions\MissingSecretException;
use Core45\HCaptcha\HCaptchaManager;
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
 * the token on the first call and be told `already-seen-response` on the
 * second.
 */
final class HttpVerifier implements Verifier
{
    /**
     * Verdicts already obtained this request, keyed by token hash and scope.
     *
     * @var array<string, VerificationResult>
     */
    private array $memo = [];

    /**
     * Whether the "hostname check is inactive" error has been logged by this
     * process. Static on purpose: the verifier is request-scoped, and the
     * point is to log once per worker, not once per request.
     */
    private static bool $hostnameCheckInactiveLogged = false;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
        private readonly VerificationLogger $audit,
    ) {}

    /**
     * Reset the once-per-process log latches. For tests.
     */
    public static function forgetLoggedWarnings(): void
    {
        self::$hostnameCheckInactiveLogged = false;
    }

    public function verify(?string $token, ?string $clientIp = null, string|VerificationContext|null $scope = null): VerificationResult
    {
        $context = VerificationContext::from($scope);

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

            $result = VerificationResult::oversizedToken();

            // Same reasoning as the missing token: free to trigger, so opt-in.
            // The hash is omitted -- hashing an attacker-sized body is work
            // the size limit exists to avoid.
            if ($this->config->get('hcaptcha.logging.log_oversized_token', false)) {
                $this->audit->record($result, null, $clientIp);
            }

            return $result;
        }

        $tokenHash = hash('sha256', $token);

        // No scope means no memoization. Sharing one anonymous bucket would let
        // a pass obtained for one action be reused by an unrelated one, so an
        // unscoped caller instead pays a real request and hCaptcha answers
        // `already-seen-response` on the second attempt -- which is correct
        // for a single-use token. The expected sitekey is part of the key too:
        // a verdict obtained for one key must not vouch for another.
        $scopeKey = $context->scope();

        $key = $scopeKey === null
            ? null
            : $tokenHash.'|'.$scopeKey.'|'.($context->sitekey ?? '');

        if ($key !== null && array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $result = $this->assert($this->call($token, $clientIp, $context));

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
    private function call(string $token, ?string $clientIp, VerificationContext $context): VerificationResult
    {
        $secret = $this->config->get('hcaptcha.secret');

        // Rejects thinhbuzz/laravel-h-captcha's 'default_secret' placeholder as
        // well as an unset value. Accepting it would fail every verification
        // with no indication why, which is how that package behaved.
        if (! HCaptchaManager::isUsableCredential($secret)) {
            throw MissingSecretException::make();
        }

        /** @var string $secret */
        $body = [
            'secret' => trim($secret),
            'response' => $token,
        ];

        if ($clientIp !== null && $clientIp !== '') {
            $body['remoteip'] = $clientIp;
        }

        // The context's sitekey wins: it is the key the widget was rendered
        // with, supplied by server code. hCaptcha answers
        // `sitekey-secret-mismatch` if the token was minted for another key.
        $sitekey = $context->sitekey !== null && $context->sitekey !== '' ? $context->sitekey : $this->config->get('hcaptcha.sitekey');

        if ($this->config->get('hcaptcha.send_sitekey', true) && is_string($sitekey) && $sitekey !== '') {
            $body['sitekey'] = $sitekey;
        }

        try {
            $response = $this->http
                ->asForm()
                ->timeout(max(1, (int) $this->config->get('hcaptcha.timeout', 10)))
                // retry() counts total attempts, so the configured number of
                // *additional* attempts is offset by one. Retrying a
                // single-use token is a gamble: if the first attempt reached
                // hCaptcha and only the reply was lost, the retry is told
                // `already-seen-response`. Hence the default of zero.
                ->retry(max(1, (int) $this->config->get('hcaptcha.retries', 0) + 1), 150, throw: false)
                ->post(
                    (string) $this->config->get('hcaptcha.endpoint', 'https://api.hcaptcha.com/siteverify'),
                    $body,
                );
        } catch (Throwable $exception) {
            $this->logger->warning('hCaptcha verification could not reach the service.', [
                'exception' => $exception->getMessage(),
            ]);

            return $this->unavailable();
        }

        $payload = $response->json();

        // A non-2xx status with a decodable siteverify body is still hCaptcha's
        // verdict (a 400 with `bad-request`, for instance), and it must fail
        // closed like any other rejection. Only a body we cannot read as a
        // verdict is an outage, because there is no verdict to apply.
        if (is_array($payload) && array_key_exists('success', $payload)) {
            if ($response->failed()) {
                $this->logger->warning('hCaptcha verification returned an error status with a verdict body.', [
                    'status' => $response->status(),
                ]);
            }

            /** @var array<string, mixed> $payload */
            return VerificationResult::fromResponse($payload);
        }

        if ($response->failed()) {
            $this->logger->warning('hCaptcha verification returned an error status.', [
                'status' => $response->status(),
            ]);

            return $this->unavailable();
        }

        $this->logger->warning('hCaptcha verification returned an unreadable body.');

        return $this->unavailable();
    }

    /**
     * Apply the local assertions hCaptcha cannot make for us.
     */
    private function assert(VerificationResult $result): VerificationResult
    {
        // An outage verdict carries no hostname and no score. Under fail-open
        // it is accepted as a matter of policy; under fail-closed it is
        // already rejected. Either way there is nothing to assert against.
        if ($result->serviceUnavailable || $result->failed()) {
            return $result;
        }

        $hostnames = $this->allowedHostnames();

        if ($hostnames !== []) {
            $hostname = mb_strtolower((string) $result->hostname);

            if ($hostname === '' || $hostname === 'not-provided') {
                // hCaptcha documents the hostname as browser-derived and
                // optional: it may be `not-provided` under load. Rejecting it
                // by default would drop genuine traffic during hCaptcha's own
                // busy periods, so this is opt-in.
                if ($this->config->get('hcaptcha.hostnames_strict', false)) {
                    return $result->rejectedLocally('hostname-unknown');
                }

                $this->logger->warning('hCaptcha did not report a hostname for this token; the hostname check was skipped.', [
                    'hostname' => $result->hostname,
                ]);
            } elseif (! in_array($hostname, $hostnames, true)) {
                return $result->rejectedLocally('hostname-mismatch');
            }
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
        // those). It must not be silent, but once per process is enough.
        if ($usable === [] && ! self::$hostnameCheckInactiveLogged) {
            self::$hostnameCheckInactiveLogged = true;

            $this->logger->error(
                'hCaptcha hostname check is inactive: hcaptcha.hostnames resolved to nothing usable. '
                .'Set HCAPTCHA_HOSTNAMES, or an APP_URL that includes a scheme. Logged once per process.'
            );
        }

        return $usable;
    }

    /**
     * An outage fails closed unless the application has opted into fail-open.
     * Even then, `success` stays false: hCaptcha never said yes.
     */
    private function unavailable(): VerificationResult
    {
        if ($this->config->get('hcaptcha.fail_open', false)) {
            return new VerificationResult(
                success: false,
                errorCodes: ['service-unavailable'],
                serviceUnavailable: true,
                accepted: true,
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
