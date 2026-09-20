<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Http\Middleware;

use Closure;
use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Support\VerificationContext;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level hCaptcha guard, for forms whose controller you do not want to
 * touch.
 *
 *     Route::post('/contact', ContactController::class)->middleware('hcaptcha');
 *     Route::post('/contact', ContactController::class)->middleware('hcaptcha:h-captcha-response,20000000-ffff-ffff-ffff-000000000002');
 *     Route::post('/signup', SignupController::class)->middleware('hcaptcha:h-captcha-response,,marketing');
 *
 * The second parameter is the sitekey the widget was rendered with, needed
 * only when it differs from the configured one. The third names a credential
 * profile from `hcaptcha.profiles`, which supplies both the expected sitekey
 * and the secret -- the widget must have been rendered with the same profile.
 * Both come from the route definition, which is server code, never from the
 * request.
 *
 * A rejection throws ValidationException, so the failure arrives as a 422 with
 * a message bag rather than a 500 -- and as a redirect-with-errors for a
 * regular form post.
 *
 * Safe to stack with the validation rule on the same field: the middleware
 * scopes by field (and sitekey, when given) and the rule scopes the same way
 * outside Livewire, so the single-use token is spent once. Inside a Livewire
 * request the rule additionally scopes by component; stack the middleware on
 * a Livewire update route and the two will not share a verdict.
 *
 * Put this on the state-changing route only, never on a route group that also
 * serves the GET which renders the form -- it verifies every request it sees,
 * including GETs, so the form page itself would fail.
 *
 * It deliberately has no method allowlist. One keyed on `$request->method()`
 * would be spoofable: Symfony's `_method` override refuses only GET, HEAD,
 * CONNECT and TRACE, so a POST carrying `_method=OPTIONS` reports OPTIONS and
 * would have skipped the check while the controller still received the full
 * POST body. A route that 422s loudly in development beats one that can be
 * silently bypassed in production.
 */
class VerifyHCaptcha
{
    public function __construct(
        protected Verifier $verifier,
        protected Repository $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $field = null, ?string $sitekey = null, ?string $profile = null): Response
    {
        $field ??= $this->fieldName();

        // input() applies dot notation, so a configured field name containing
        // a dot would be read as a nested path. Prefer the literal key.
        $all = $request->all();
        $token = array_key_exists($field, $all) ? $all[$field] : $request->input($field);

        $result = $this->verifier->verify(
            is_string($token) ? $token : null,
            $request->ip(),
            new VerificationContext(
                field: $field,
                sitekey: $sitekey !== null && $sitekey !== '' ? $sitekey : null,
                profile: $profile !== null && $profile !== '' ? $profile : null,
            ),
        );

        if ($result->failed()) {
            throw ValidationException::withMessages([
                $field => [__($result->messageKey())],
            ]);
        }

        return $next($request);
    }

    protected function fieldName(): string
    {
        $field = $this->config->get('hcaptcha.field', 'h-captcha-response');

        return is_string($field) && $field !== '' ? $field : 'h-captcha-response';
    }
}
