<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Support;

use Core45\HCaptcha\Contracts\HostnameProvider;
use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Events\VerificationCompleted;
use Core45\HCaptcha\Exceptions\MissingSecretException;
use Core45\HCaptcha\HCaptchaManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
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

    private readonly Credentials $credentials;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
        private readonly VerificationLogger $audit,
        private readonly Dispatcher $events,
        private readonly HostnameProvider $hostnames,
    ) {
        $this->credentials = new Credentials($config);
    }

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
        // a verdict obtained for one key -- or one credential profile -- must
        // not vouch for another.
        $scopeKey = $context->scope();

        $key = $scopeKey === null
            ? null
            : $tokenHash.'|'.$scopeKey.'|'.$context->credentialKey();

        if ($key !== null && array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $result = $this->assert($this->call($token, $clientIp, $context));

        // The audit row records the token hash alone. The scope is a memo
        // concern; mixing it in would break replay correlation across scopes.
        $this->audit->record($result, $tokenHash, $clientIp);
        $this->report($result);

        // After the audit row, so a listener that queries the trail sees its
        // own verification in it. Dispatched here rather than in verify()'s
        // memo branch: one token checked by both the middleware and the rule
        // is one verification, and firing twice would over-report.
        $this->events->dispatch(new VerificationCompleted($result, $context, $tokenHash));

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
        // A named profile supplies both halves of the credential pair, falling
        // back to the global ones for whichever half it leaves unset.
        $credentials = $this->credentials->for($context->profile);

        $secret = $credentials['secret'];

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
        $sitekey = $context->sitekey !== null && $context->sitekey !== '' ? $context->sitekey : $credentials['sitekey'];

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

        // Any array body on a 2xx response, or a non-2xx body carrying a
        // `success` key, is hCaptcha's verdict, including one with no
        // `success` key at all: fromResponse() reads that as a rejection, which
        // is the fail-closed answer a malformed success response must get. A
        // non-2xx body counts as a verdict only when it carries `success`
        // (a 400 with `bad-request`, for instance); anything else non-2xx is an
        // outage, because there is no verdict to apply.
        if (is_array($payload) && (! $response->failed() || array_key_exists('success', $payload))) {
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

        if ($hostnames === []) {
            // Nothing to compare against. Skipping the check here would accept a
            // token solved on any page in the world, which is the one thing the
            // hostname check exists to stop, so by default this rejects instead.
            if ((bool) $this->config->get('hcaptcha.hostnames_required', true)) {
                // Not latched: a misconfiguration that rejects live traffic must
                // stay greppable for as long as it lasts.
                $this->logger->error(
                    'hCaptcha hostname allowlist resolved to nothing usable, so this token was rejected. '
                    .'Bind a HostnameProvider, set HCAPTCHA_HOSTNAMES, or set an APP_URL that includes a '
                    .'scheme. Set HCAPTCHA_HOSTNAMES_REQUIRED=false to skip the check instead of rejecting.'
                );

                return $result->rejectedLocally('hostname-allowlist-empty');
            }

            $this->reportHostnameCheckInactive();
        } else {
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
     * The allowlist for this verification.
     *
     * Resolved per call, never held across requests: a provider backed by a
     * database is the point of the contract, and a worker that cached the list
     * would keep serving a stale one until it restarted.
     *
     * A provider that returns nothing hands over to `hcaptcha.hostnames` rather
     * than ending the search. That keeps a provider outage -- or a tenant table
     * that is briefly empty mid-migration -- from silently widening the
     * allowlist to everything.
     *
     * @return list<string>
     */
    private function allowedHostnames(): array
    {
        $provided = HostnameNormalizer::normalize($this->hostnames->hostnames());

        if ($provided !== []) {
            return $provided;
        }

        return HostnameNormalizer::normalize($this->config->get('hcaptcha.hostnames'));
    }

    /**
     * Say once per process that the check is off. Only reachable when the
     * application has explicitly opted out via `hcaptcha.hostnames_required`.
     */
    private function reportHostnameCheckInactive(): void
    {
        if (self::$hostnameCheckInactiveLogged) {
            return;
        }

        self::$hostnameCheckInactiveLogged = true;

        $this->logger->error(
            'hCaptcha hostname check is inactive: the allowlist resolved to nothing usable and '
            .'hcaptcha.hostnames_required is false, so tokens are being accepted whatever hostname '
            .'they were solved on. Logged once per process.'
        );
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
