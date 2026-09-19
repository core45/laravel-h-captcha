<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Support;

/**
 * What a token is being verified for.
 *
 * `field` is the request attribute the token arrived in. `action` identifies
 * the protected operation when the framework can tell us: for Livewire that is
 * the executing component, so two components validating the same attribute in
 * one batched request do not share a verdict. `sitekey` is the key the widget
 * was rendered with, when it differs from the configured one; it is server
 * code that supplies it, never request input.
 *
 * The memo in HttpVerifier is keyed on scope(). Two checks with the same scope
 * in one request cost one HTTP call, which is what lets the middleware and the
 * validation rule guard the same field without spending the single-use token
 * twice.
 */
final readonly class VerificationContext
{
    public function __construct(
        public ?string $field = null,
        public ?string $action = null,
        public ?string $sitekey = null,
    ) {}

    public static function forField(string $field): self
    {
        return new self(field: $field);
    }

    /**
     * Accept the 1.x string scope as well as a context, so callers written
     * against the old signature keep working.
     */
    public static function from(string|self|null $scope): self
    {
        if ($scope instanceof self) {
            return $scope;
        }

        return new self(field: $scope);
    }

    /**
     * The memo key component. Null means "do not memoize".
     *
     * The action is prefixed even when empty so that `|field` from a plain
     * form can never collide with `field` used as an action name.
     */
    public function scope(): ?string
    {
        $field = $this->field === '' ? null : $this->field;
        $action = $this->action === '' ? null : $this->action;

        if ($field === null && $action === null) {
            return null;
        }

        return ($action ?? '').'|'.($field ?? '');
    }
}
