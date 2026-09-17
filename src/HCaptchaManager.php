<?php

declare(strict_types=1);

namespace Core45\HCaptcha;

use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Exceptions\MissingSitekeyException;
use Core45\HCaptcha\Support\VerificationResult;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Presentation side of the package: everything the widget needs in order to
 * render, plus a pass-through to the verifier so `HCaptcha::verify()` reads
 * naturally from application code.
 *
 * Config is read here rather than in the views, so that a published view stays
 * a template instead of acquiring its own opinions about defaults.
 */
class HCaptchaManager
{
    /**
     * Widget ids rendered during this request, in render order.
     *
     * @var list<string>
     */
    protected array $renderedWidgets = [];

    public function __construct(
        protected Repository $config,
        protected Verifier $verifier,
        protected Application $app,
    ) {}

    /**
     * Verify a token. Idempotent per request -- see the Verifier contract.
     */
    public function verify(?string $token, ?string $clientIp = null, ?string $scope = null): VerificationResult
    {
        // Defaults to the configured field name rather than to no scope. An
        // empty scope is a shared memo bucket, so leaving it unset here would
        // let manual verification reuse a pass across unrelated actions --
        // exactly the hole the scope exists to close.
        return $this->verifier->verify($token, $clientIp, $scope ?? $this->fieldName());
    }

    public function sitekey(?string $override = null): string
    {
        $sitekey = $override ?? $this->config->get('hcaptcha.sitekey');

        if (! is_string($sitekey) || trim($sitekey) === '') {
            throw MissingSitekeyException::make();
        }

        return $sitekey;
    }

    /**
     * Whether a site key is configured, without throwing. Lets a view degrade
     * instead of taking the whole page down.
     */
    public function configured(): bool
    {
        $sitekey = $this->config->get('hcaptcha.sitekey');

        return is_string($sitekey) && trim($sitekey) !== '';
    }

    /**
     * The POST field the widget writes its token into.
     */
    public function fieldName(): string
    {
        $field = $this->config->get('hcaptcha.field', 'h-captcha-response');

        return is_string($field) && $field !== '' ? $field : 'h-captcha-response';
    }

    /**
     * The widget UI language.
     *
     * Resolved here, at render time, and never in the config file: a config
     * file that calls app()->getLocale() freezes the locale into
     * bootstrap/cache/config.php the moment `config:cache` runs.
     */
    public function locale(?string $override = null): ?string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        $configured = $this->config->get('hcaptcha.locale');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->app->getLocale();
    }

    public function scriptEnabled(): bool
    {
        return (bool) $this->config->get('hcaptcha.script.enabled', true);
    }

    /**
     * The api.js URL, with the language and the render/onload parameters.
     *
     * The widget is always rendered explicitly. hCaptcha's auto mode scans for
     * `.h-captcha` once on DOMContentLoaded and injects its own response field,
     * which loses the token on any DOM patch and bypasses the hidden input this
     * package tracks. Explicit render is also what makes Livewire work, so
     * there is no second mode to choose between.
     */
    public function scriptUrl(?string $locale = null): string
    {
        $query = [
            'render' => 'explicit',
            'onload' => $this->callbackName(),
        ];

        $language = $this->locale($locale);

        if ($language !== null && $language !== '') {
            $query['hl'] = $language;
        }

        $url = (string) $this->config->get('hcaptcha.script.url', 'https://js.hcaptcha.com/1/api.js');

        return $url.'?'.http_build_query($query);
    }

    /**
     * Merge the configured widget attributes with per-widget overrides.
     *
     * Keys are normalised to `data-*`, so a caller can write either
     * `['theme' => 'dark']` or `['data-theme' => 'dark']`.
     *
     * @param  array<string, string|int|float|bool|null>  $overrides
     * @return array<string, string>
     */
    public function attributes(array $overrides = [], ?string $sitekey = null): array
    {
        $configured = $this->config->get('hcaptcha.attributes', []);

        $merged = array_merge(
            is_array($configured) ? $configured : [],
            $overrides,
        );

        $attributes = [];

        foreach ($merged as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            $attributes[$this->normaliseAttribute((string) $key)] = $value === true ? 'true' : (string) $value;
        }

        $attributes['data-sitekey'] = $this->sitekey($sitekey);

        return $attributes;
    }

    /**
     * Render attributes to an HTML attribute string, escaping every value.
     *
     * The package this one replaces interpolated attribute values raw, which
     * turned every attribute into an injection point.
     *
     * @param  array<string, string>  $attributes
     */
    public function attributeString(array $attributes): HtmlString
    {
        $rendered = [];

        foreach ($attributes as $key => $value) {
            // Validated here as well as in normaliseAttribute(): this method is
            // public, so keys can arrive without having passed through there.
            $rendered[] = $this->assertAttributeName((string) $key).'="'.e($value).'"';
        }

        return new HtmlString($rendered === [] ? '' : ' '.implode(' ', $rendered));
    }

    /**
     * A DOM id for a widget, remembered so the explicit-render script can find
     * every widget on the page.
     */
    public function widgetId(?string $override = null): string
    {
        $id = $override !== null && $override !== ''
            ? $override
            : 'hcaptcha-'.Str::random(12);

        if (! in_array($id, $this->renderedWidgets, true)) {
            $this->renderedWidgets[] = $id;
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    public function renderedWidgets(): array
    {
        return $this->renderedWidgets;
    }

    /**
     * Name of the global the explicit-render callback is published under. This
     * is hCaptcha's `onload` target, so it has to be a bare global.
     */
    public function callbackName(): string
    {
        return $this->assertJsIdentifier('core45HCaptchaOnLoad');
    }

    /**
     * Name of the global object exposing `render`, `reset` and `widgets`.
     */
    public function namespaceName(): string
    {
        return $this->assertJsIdentifier('core45HCaptcha');
    }

    /**
     * Both names are interpolated into a `<script>` block, where Blade's HTML
     * escaping is the wrong tool: entities are not decoded in raw-text context
     * and `</script` is not neutralised. These values are hardcoded today, so
     * this is a latch rather than a fix -- it makes either one impossible to
     * turn into stored XSS by later making it configurable.
     *
     * @throws InvalidArgumentException
     */
    protected function assertJsIdentifier(string $name): string
    {
        if (in_array(preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*\z/', $name), [0, false], true)) {
            throw new InvalidArgumentException(sprintf(
                'hCaptcha JavaScript identifier [%s] is not a bare identifier and cannot be emitted into a script tag.',
                $name,
            ));
        }

        return $name;
    }

    /**
     * hCaptcha reads its widget options from `data-*` attributes. Anything that
     * is not already prefixed, and is not a plain HTML attribute we pass
     * through, gets the prefix.
     *
     * The key is validated, not merely escaped. `e()` escapes `& < > " '` but
     * not whitespace or `=`, so a key such as `data-x onmouseover=alert(1)`
     * would render as a second, live attribute -- escaping the value is
     * airtight, escaping the key is not.
     *
     * @throws InvalidArgumentException
     */
    protected function normaliseAttribute(string $key): string
    {
        $normalised = str_starts_with($key, 'data-') || in_array($key, ['id', 'class', 'style'], true)
            ? $key
            : 'data-'.$key;

        return $this->assertAttributeName($normalised);
    }

    /**
     * `\z` rather than `$`, which would also accept a trailing newline.
     *
     * @throws InvalidArgumentException
     */
    protected function assertAttributeName(string $name): string
    {
        if (in_array(preg_match('/\A[A-Za-z][A-Za-z0-9._-]*\z/', $name), [0, false], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid hCaptcha widget attribute name [%s]. Names must start with a letter and may contain letters, digits, dots, underscores and hyphens only.',
                $name,
            ));
        }

        return $name;
    }
}
