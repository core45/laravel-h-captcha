<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Compat;

use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\View\Components\HCaptcha as HCaptchaComponent;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

/**
 * Drop-in replacement for `thinhbuzz/laravel-h-captcha`'s `Captcha` class.
 *
 * Bound as `captcha` and aliased as the `Captcha` facade, so an application
 * migrating from that package keeps working without a code change. Every method
 * here mirrors the signature it had there, including `verify()` returning a
 * plain bool rather than a result object.
 *
 * This is a translation layer, not a second implementation: rendering goes
 * through the real Blade component and verification through the one `Verifier`,
 * so the compat surface cannot drift from the native one.
 *
 * New code should prefer `<x-hcaptcha />`, the `HCaptcha` facade and
 * `Rules\HCaptcha`, which report *why* a token failed.
 */
class CaptchaCompat
{
    public function __construct(
        protected HCaptchaManager $manager,
        protected Verifier $verifier,
        protected Repository $config,
    ) {}

    /**
     * Render a widget.
     *
     * The reference accepted HTML/data attributes in `$attributes` and widget
     * options in `$options`, including the pseudo-attribute `add-js` to
     * suppress the script tag.
     *
     * @param  array<string, string|int|float|bool|null>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function display(array $attributes = [], array $options = []): HtmlString
    {
        $addJs = Arr::get($attributes, 'add-js', true);

        unset($attributes['add-js']);

        $id = Arr::pull($attributes, 'id');

        $component = new HCaptchaComponent(
            manager: $this->manager,
            sitekey: $this->stringOrNull(Arr::get($options, 'sitekey')),
            locale: $this->stringOrNull(Arr::get($options, 'lang') ?? Arr::get($options, 'options.lang')),
            id: $this->stringOrNull($id),
            script: (bool) $addJs,
            options: $attributes,
        );

        return new HtmlString(
            $component->resolveView()->with($component->data())->render()
        );
    }

    /**
     * In the reference this emitted the explicit-render bootstrap for multiple
     * widgets. Here every widget already renders explicitly and the bootstrap
     * script renders all of them, so there is nothing to emit -- returning an
     * empty string keeps old calls working without double-rendering anything.
     *
     * @param  array<string, mixed>  $globalOptions
     */
    public function displayMultiple(array $globalOptions = []): HtmlString
    {
        return new HtmlString('');
    }

    /**
     * The api.js script tag on its own.
     *
     * @param  array<string, mixed>  $options
     * @param  list<string>  $attributes
     */
    public function displayJs(array $options = [], array $attributes = ['async', 'defer']): HtmlString
    {
        $url = $this->manager->scriptUrl(
            $this->stringOrNull(Arr::get($options, 'lang') ?? Arr::get($options, 'options.lang'))
        );

        return new HtmlString(
            '<script src="'.e($url).'" '.implode(' ', array_map(e(...), $attributes)).'></script>'
        );
    }

    /**
     * Multiple-widget mode was a rendering switch in the reference. It is
     * always effectively on here, so this only records the flag for any caller
     * that reads it back.
     */
    public function multiple(bool $multiple = true): void
    {
        $this->config->set('captcha.options.multiple', $multiple);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function setOptions(array $options = []): void
    {
        $this->config->set('captcha.options', $options);

        if (array_key_exists('lang', $options)) {
            $this->config->set('hcaptcha.locale', $options['lang']);
        }
    }

    /**
     * Verify a token.
     *
     * Returns a bool to match the reference, because callers write
     * `if (Captcha::verify(...))`. Use `HCaptcha::verify()` for the reason.
     *
     * @param  array<string, mixed>  $options
     */
    public function verify(?string $response, ?string $clientIp = null, array $options = []): bool
    {
        $scope = $this->stringOrNull(Arr::get($options, 'scope')) ?? $this->manager->fieldName();

        return $this->manager->verify($response, $clientIp, $scope)->passed();
    }

    /**
     * Kept for callers that referenced the reference's JS globals. The names
     * differ because the bootstrap script is this package's own.
     */
    public function getWidgetIdName(): string
    {
        return $this->manager->namespaceName().'.widgets';
    }

    public function getJsVariableName(): string
    {
        return 'hcaptcha';
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
