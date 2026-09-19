<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Contracts;

use Core45\HCaptcha\Support\VerificationContext;
use Core45\HCaptcha\Support\VerificationResult;

/**
 * The single seam through which every hCaptcha token is checked.
 *
 * hCaptcha tokens are single-use. The validation rule, the middleware, the
 * Blade component and the Filament field all route through one implementation
 * of this interface so that a form carrying one token never spends it twice --
 * the second call would come back `already-seen-response` and the visitor
 * would see a failure they cannot act on.
 */
interface Verifier
{
    /**
     * Verify a token, returning the verdict rather than throwing on rejection.
     *
     * Implementations must be idempotent per `(token, context scope)` within a
     * single request: verifying the same token twice for the same scope issues
     * one HTTP request.
     *
     * `$scope` identifies the thing being protected. A string is treated as
     * the field name, for callers written against 1.x. A VerificationContext
     * can also carry the protected action and the expected sitekey. The
     * memoization is a safety mechanism, not a cache, and an unscoped memo is
     * a bypass: Livewire processes up to 200 components in a single HTTP
     * request, so one solved captcha would otherwise authorise every one of
     * them. Two checks of the same field in the same action (the rule and the
     * middleware) share a scope and collapse to one call; two different fields
     * or two different actions do not, and hCaptcha answers
     * `already-seen-response` to the second, which is correct.
     */
    public function verify(?string $token, ?string $clientIp = null, string|VerificationContext|null $scope = null): VerificationResult;

    /**
     * Forget every memoized verdict. Intended for long-running workers and
     * tests, not for request handling.
     */
    public function flush(): void;
}
