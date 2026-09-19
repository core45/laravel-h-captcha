<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Rules;

use Closure;
use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Support\VerificationContext;
use Core45\HCaptcha\Support\VerificationResult;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Translation\PotentiallyTranslatedString;
use Livewire\Component as LivewireComponent;
use Livewire\LivewireManager;

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
 *
 * Inside a Livewire request the executing component becomes part of the memo
 * scope, so two components validating the same property name in one batched
 * request cannot share a verdict.
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

    /**
     * @param  string|null  $sitekey  The sitekey the widget was rendered with,
     *                                when it is not the configured one. Server
     *                                code supplies this; never request input.
     * @param  string|null  $action  Overrides the derived action identity.
     */
    public function __construct(
        protected ?Verifier $verifier = null,
        protected ?string $clientIp = null,
        protected ?string $sitekey = null,
        protected ?string $action = null,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $result = $this->resultFor($attribute, $value);

        if ($result->failed()) {
            $fail($result->messageKey())->translate();
        }
    }

    /**
     * The verdict for an attribute, without failing a validator. Used by the
     * `hcaptcha` string rule, which has to return a bool and stash the
     * message key separately.
     */
    public function resultFor(string $attribute, mixed $value): VerificationResult
    {
        $token = is_string($value) ? $value : null;

        return $this->verifier()->verify($token, $this->clientIp(), $this->context($attribute));
    }

    /**
     * The attribute is the field: the rule and the middleware guarding the
     * same field collapse to one HTTP call, while a second field does not
     * ride on the first field's solved captcha.
     */
    protected function context(string $attribute): VerificationContext
    {
        return new VerificationContext(
            field: $attribute,
            action: $this->action ?? $this->currentLivewireAction(),
            sitekey: $this->sitekey,
        );
    }

    /**
     * Identify the Livewire component whose request is being handled, if any.
     *
     * Livewire keeps a stack of the components it is currently processing;
     * outside a Livewire request the stack is empty and this returns null, so
     * a plain form scopes by field alone.
     */
    protected function currentLivewireAction(): ?string
    {
        if (! class_exists(LivewireManager::class) || ! app()->bound(LivewireManager::class)) {
            return null;
        }

        $component = app(LivewireManager::class)->current();

        if (! $component instanceof LivewireComponent) {
            return null;
        }

        return $component::class.'#'.$component->getId();
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
