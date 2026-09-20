<?php

declare(strict_types=1);

namespace Core45\HCaptcha;

use Closure;
use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Exceptions\MissingSitekeyException;
use Core45\HCaptcha\Support\Credentials;
use Core45\HCaptcha\Support\LivewireContext;
use Core45\HCaptcha\Support\VerificationContext;
use Core45\HCaptcha\Support\VerificationResult;
use Core45\HCaptcha\Testing\FakeVerifier;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
     * Values that look like credentials but are not.
     *
     * thinhbuzz/laravel-h-captcha defaulted its config to these literals, so a
     * half-configured install carries them in `.env` or in a published
     * `config/captcha.php`. Accepting them would reject every visitor with no
     * explanation, so they are treated as absent and the real problem surfaces.
     *
     * @var list<string>
     */
    public const PLACEHOLDER_CREDENTIALS = ['default_sitekey', 'default_secret'];

    /**
     * Widget ids rendered during this request, in render order.
     *
     * @var list<string>
     */
    protected array $renderedWidgets = [];

    /**
     * Render counters per id scope (a Livewire component id, or `page`).
     *
     * @var array<string, int>
     */
    protected array $widgetCounters = [];

    /**
     * The object id of the Livewire component instance last seen rendering
     * each scope.
     *
     * A component instance is deserialized fresh for every Livewire request,
     * so a new object id for a scope we have already counted for means this
     * is a new render pass of that component and the counter must restart --
     * otherwise a re-render would keep counting up instead of reproducing the
     * ids it produced the first time.
     *
     * @var array<string, int>
     */
    protected array $widgetScopeRenders = [];

    /**
     * Whether the "widget degraded for want of a site key" warning has been
     * logged by this process. Static for the same reason as
     * `HttpVerifier::$hostnameCheckInactiveLogged`: the point is to log once
     * per worker, not once per widget or per request, so a page rendering
     * several misconfigured widgets does not flood the log.
     */
    private static bool $misconfiguredLogged = false;

    /**
     * Resolver for the named credential profiles. Built lazily rather than
     * injected: the verifier needs the same resolver, and the manager already
     * depends on the verifier.
     */
    protected ?Credentials $credentials = null;

    /**
     * Whether a configured value is a real credential, rather than unset or one
     * of the placeholders above.
     */
    public static function isUsableCredential(mixed $value): bool
    {
        return is_string($value)
            && trim($value) !== ''
            && ! in_array(trim($value), self::PLACEHOLDER_CREDENTIALS, true);
    }

    public function __construct(
        protected Repository $config,
        protected Verifier $verifier,
        protected Application $app,
        protected LoggerInterface $logger,
    ) {}

    /**
     * Reset the once-per-process log latch. For tests.
     */
    public static function forgetLoggedWarnings(): void
    {
        self::$misconfiguredLogged = false;
    }

    /**
     * Verify a token. Idempotent per request -- see the Verifier contract.
     */
    public function verify(?string $token, ?string $clientIp = null, string|VerificationContext|null $scope = null): VerificationResult
    {
        // Defaults to the configured field name rather than to no scope. An
        // empty scope is a shared memo bucket, so leaving it unset here would
        // let manual verification reuse a pass across unrelated actions --
        // exactly the hole the scope exists to close.
        return $this->verifier->verify($token, $clientIp, $scope ?? VerificationContext::forField($this->fieldName()));
    }

    /**
     * Whether `HCaptcha::fake()` is in force. The views ask, so that a test
     * renders a widget it can solve without loading hCaptcha's SDK or opening
     * a socket. Nothing in the request path branches on this -- a fake
     * verifier can only be installed by test code.
     */
    public function faking(): bool
    {
        return $this->verifier instanceof FakeVerifier;
    }

    public function sitekey(?string $override = null): string
    {
        $sitekey = $override ?? $this->config->get('hcaptcha.sitekey');

        if (! self::isUsableCredential($sitekey)) {
            throw MissingSitekeyException::make();
        }

        /** @var string $sitekey */
        return trim($sitekey);
    }

    /**
     * The credentials a named profile resolves to.
     *
     * @return array{sitekey: mixed, secret: mixed}
     *
     * @throws InvalidArgumentException when the profile is unknown.
     */
    public function profileCredentials(?string $profile): array
    {
        return $this->credentials()->for($profile);
    }

    /**
     * The sitekey a named profile renders with, or null when it resolves to
     * nothing usable -- so a view can degrade rather than throw.
     *
     * @throws InvalidArgumentException when the profile is unknown.
     */
    public function profileSitekey(?string $profile): ?string
    {
        return $this->credentials()->sitekeyFor($profile);
    }

    /**
     * Names of the configured credential profiles.
     *
     * @return list<string>
     */
    public function profileNames(): array
    {
        return $this->credentials()->names();
    }

    protected function credentials(): Credentials
    {
        return $this->credentials ??= new Credentials($this->config);
    }

    /**
     * Whether a usable site key is available, without throwing. Lets a view
     * degrade instead of taking the whole page down. An explicit override is
     * judged on its own: a placeholder override is "not configured" even when
     * the global key is fine, because rendering the global key under a widget
     * that asked for another would verify against the wrong key.
     */
    public function configured(?string $override = null): bool
    {
        return self::isUsableCredential($override ?? $this->config->get('hcaptcha.sitekey'));
    }

    /**
     * Warn once per process that a widget degraded because no usable site key
     * resolved. `configured()` above is a pure query -- anything can call it to
     * ask whether a key is usable, including compatibility code and future
     * diagnostics, without that call meaning a widget rendered nothing. This
     * method is the operational side effect, and it is the render paths'
     * responsibility to call it once they have actually decided to degrade:
     * the Blade component's constructor and the Filament field's view both do,
     * so an operator gets the same signal regardless of which one they used.
     *
     * Skipped when `app.debug` is true: the visible `hcaptcha-misconfigured`
     * notice the view renders in that case already tells the developer, and
     * logging on top of it would be redundant.
     */
    public function logMisconfigured(): void
    {
        if (self::$misconfiguredLogged || $this->config->get('app.debug')) {
            return;
        }

        self::$misconfiguredLogged = true;

        $this->logger->warning(
            'hCaptcha widget rendered nothing: no usable HCAPTCHA_SITEKEY (or hcaptcha.sitekey) is configured. '
            .'Validation still rejects every submission, so the form fails closed with no way for a visitor to satisfy it. '
            .'Logged once per process.'
        );
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
     * A DOM id for a widget, deterministic so that a Livewire re-render binds
     * to the same hidden input it rendered with the first time.
     *
     * Without an override the id is `hcaptcha-{scope}-{key}`: `scope` is the
     * executing Livewire component's id, or `page` outside Livewire; `key` is
     * the caller's key (Filament passes the state path) or a per-scope render
     * counter. Livewire renders a component in a fresh request, so the counter
     * restarts and the same widget gets the same id every time.
     */
    public function widgetId(?string $override = null, ?string $key = null): string
    {
        if ($override !== null && $override !== '') {
            $id = $this->domId($override);
        } else {
            $component = LivewireContext::component();
            $scope = $component?->getId() ?? 'page';

            if ($key === null || $key === '') {
                if ($component !== null) {
                    // Belt and braces for the test harness, not for Octane.
                    // The manager is a scoped binding, so a real request --
                    // FPM or Octane alike -- always starts with fresh
                    // counters. Livewire::test() reuses one manager instance
                    // across mount and a following update, which would keep
                    // incrementing; keying the counter on the rendering
                    // component object restarts it there the way a genuine
                    // request boundary would.
                    $renderToken = spl_object_id($component);

                    if (($this->widgetScopeRenders[$scope] ?? null) !== $renderToken) {
                        $this->widgetScopeRenders[$scope] = $renderToken;
                        $this->widgetCounters[$scope] = 0;
                    }
                }

                $this->widgetCounters[$scope] = ($this->widgetCounters[$scope] ?? 0) + 1;
                $key = (string) $this->widgetCounters[$scope];
            }

            $id = $this->domId('hcaptcha-'.$scope.'-'.$key);
        }

        if (! in_array($id, $this->renderedWidgets, true)) {
            $this->renderedWidgets[] = $id;
        }

        return $id;
    }

    /**
     * Collapse anything outside `[A-Za-z0-9_-]` into a hyphen, so a state path
     * such as `data.h-captcha-response` or a caller's free text becomes a
     * selector-safe id.
     */
    protected function domId(string $value): string
    {
        $id = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $value), '-');

        return $id === '' ? 'hcaptcha' : $id;
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
     * Browser event the validation rule dispatches through Livewire after a
     * token was verified, so the widget that produced it can be reset.
     */
    public const RESET_EVENT = 'core45HCaptcha:reset';

    /**
     * The bootstrap JavaScript with the namespace and onload identifiers
     * substituted. Kept in a .js file so it stays readable and lintable; the
     * two identifiers are validated bare identifiers, never user input.
     */
    public function bootstrapScript(): HtmlString
    {
        $source = file_get_contents(__DIR__.'/../resources/js/bootstrap.js');

        if ($source === false) {
            throw new RuntimeException('Unable to read the hCaptcha bootstrap script.');
        }

        return new HtmlString(strtr($source, [
            '__NAMESPACE__' => $this->namespaceName(),
            '__CALLBACK__' => $this->callbackName(),
        ]));
    }

    /**
     * Origins hCaptcha requires in a Content Security Policy. Never list a
     * specific asset subdomain: hCaptcha rotates them by region and over time.
     *
     * @var list<string>
     */
    public const CSP_SOURCES = ['https://hcaptcha.com', 'https://*.hcaptcha.com'];

    /**
     * Resolves the per-request nonce for the two script tags. Static so an
     * application registers it once in a service provider; the closure itself
     * reads per-request state, which is why it is a closure and not a value.
     *
     * @var (Closure(): ?string)|null
     */
    protected static ?Closure $nonceResolver = null;

    /**
     * @param  (Closure(): ?string)|null  $resolver
     */
    public static function nonceUsing(?Closure $resolver): void
    {
        static::$nonceResolver = $resolver;
    }

    /**
     * The nonce for this request: the registered resolver, else Laravel's
     * Vite nonce when one was set with Vite::useCspNonce(), else none.
     */
    public function nonce(): ?string
    {
        $nonce = static::$nonceResolver !== null
            ? (static::$nonceResolver)()
            : (class_exists(\Illuminate\Foundation\Vite::class) ? Vite::cspNonce() : null);

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    /**
     * ` nonce="..."` (leading space) or an empty string, escaped, for the
     * script tags.
     */
    public function nonceAttribute(): HtmlString
    {
        $nonce = $this->nonce();

        return new HtmlString($nonce === null ? '' : ' nonce="'.e($nonce).'"');
    }

    /**
     * The directives an application must allow for the widget to load.
     *
     * @return array<string, list<string>>
     */
    public static function cspDirectives(): array
    {
        return [
            'script-src' => self::CSP_SOURCES,
            'frame-src' => self::CSP_SOURCES,
            'style-src' => self::CSP_SOURCES,
            'connect-src' => self::CSP_SOURCES,
        ];
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
