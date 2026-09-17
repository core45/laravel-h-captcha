<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Contracts;

use Core45\HCaptcha\Support\VerificationResult;

/**
 * The single seam through which every hCaptcha token is checked.
 *
 * hCaptcha tokens are single-use. The validation rule, the middleware, the
 * Blade component and the Filament field all route through one implementation
 * of this interface so that a form carrying one token never spends it twice --
 * the second call would come back `token-already-used` and the visitor would
 * see a failure they cannot act on.
 */
interface Verifier
{
    /**
     * Verify a token, returning the verdict rather than throwing on rejection.
     *
     * Implementations must be idempotent per `(token, scope)` within a single
     * request: verifying the same token twice for the same scope issues one
     * HTTP request.
     *
     * `$scope` identifies the thing being protected -- the validated attribute,
     * or a Livewire state path. It exists because the memoization is a safety
     * mechanism, not a cache, and an unscoped memo is a bypass: Livewire
     * processes up to 200 components in a single HTTP request, so one solved
     * captcha would otherwise authorise every one of them. Two checks of the
     * same field (the rule and the middleware) share a scope and still collapse
     * to one call; two different fields do not, and the second is told
     * `token-already-used` by hCaptcha, which is correct.
     */
    public function verify(?string $token, ?string $clientIp = null, ?string $scope = null): VerificationResult;

    /**
     * Forget every memoized verdict. Intended for long-running workers and
     * tests, not for request handling.
     */
    public function flush(): void;
}
