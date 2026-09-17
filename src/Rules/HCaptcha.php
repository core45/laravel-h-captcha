<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Rules;

use Closure;
use Core45\HCaptcha\Contracts\Verifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates an hCaptcha token.
 *
 *     $request->validate([
 *         'h-captcha-response' => ['required', new HCaptcha],
 *     ]);
 *
 * The verifier memoizes per request, so combining this rule with the
 * middleware -- or with a Filament field that validates on update and again on
 * submit -- still spends the single-use token exactly once.
 */
class HCaptcha implements ValidationRule
{
    /**
     * Run even when the field is absent or empty.
     *
     * Without this the rule would be skipped on an empty token, so a form
     * submitted with no captcha at all would have to rely on a separate
     * `required` rule and would report "field is required" instead of
     * "please complete the captcha".
     *
     * Laravel reads this property in `InvokableValidationRule::make()`.
     */
    public bool $implicit = true;

    public function __construct(
        protected ?Verifier $verifier = null,
        protected ?string $clientIp = null,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $token = is_string($value) ? $value : null;

        // The attribute is the scope: the rule and the middleware guarding the
        // same field collapse to one HTTP call, while a second field does not
        // ride on the first field's solved captcha.
        $result = $this->verifier()->verify($token, $this->clientIp(), $attribute);

        if ($result->failed()) {
            $fail($result->messageKey())->translate();
        }
    }

    protected function verifier(): Verifier
    {
        return $this->verifier ??= app(Verifier::class);
    }

    /**
     * hCaptcha treats `remoteip` as a signal rather than an assertion, so a
     * missing or proxied address only costs accuracy.
     */
    protected function clientIp(): ?string
    {
        if ($this->clientIp !== null) {
            return $this->clientIp;
        }

        return app()->bound('request') ? app(Request::class)->ip() : null;
    }
}
