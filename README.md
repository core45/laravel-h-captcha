# core45/laravel-h-captcha

**Privacy-friendly captcha for Laravel, in one line of Blade.**

```blade
<x-hcaptcha />
```

```php
$request->validate([
    'h-captcha-response' => [new HCaptcha],
]);
```

That is a working, spam-protected form. Everything else is optional.

### Highlights

- **Every integration you need, one implementation.** A Blade component, a validation rule, route middleware, and a Filament form field — all routing through the same verifier, so they behave identically.
- **Livewire and modals just work.** Widgets render in hCaptcha's explicit mode and re-render themselves after a DOM patch, so a captcha inside a Livewire component, or in a modal opened later, is never a blank box.
- **Safe to stack.** hCaptcha tokens are single-use, so guarding one form with both the rule and the middleware would normally spend the token twice and show the visitor a failure they did nothing to cause. Here the verdict is remembered per field, so the token is spent once no matter how many guards check it.
- **Secure by default rather than by configuration.** It fails closed during an outage, rejects tokens solved on someone else's site, refuses a malformed response, and never stores a raw token.
- **No Guzzle pin.** It uses Laravel's own `Http` client, so it never argues with your other dependencies about a Guzzle major version.
- **Drop-in for `thinhbuzz/laravel-h-captcha`.** Same `.env` keys, same `Captcha` facade, same `captcha` rule — swap the package and keep your code. See [Migrating](docs/migrating-from-thinhbuzz.md).
- **22 languages**, a typed result object for when you want to know *why* a token failed, and an optional audit trail with a retention command.

Requires PHP 8.4 and Laravel 12 or 13.

## Documentation

- [Migrating from thinhbuzz/laravel-h-captcha](docs/migrating-from-thinhbuzz.md) — drop-in replacement notes for `buzz/laravel-h-captcha` users.
- [Upgrading from 1.x](docs/upgrading.md) — behaviour changes when moving a 1.x install to 2.0.0.
- [Content Security Policy](docs/content-security-policy.md) — required directives, nonces, and the Livewire `'unsafe-eval'` caveat.
- [Translations](docs/translations.md) — the 22 shipped locales and their keys.
- [Audit trail](docs/audit-trail.md) — what gets logged, PII toggles, pruning, and migration registration.
- [Support policy](docs/support-policy.md) — supported versions, semantic versioning scope, and deprecation approach.
- [Security policy](SECURITY.md) — how to report a vulnerability and what's in scope.

## Installation

```bash
composer require core45/laravel-h-captcha
```

Publish tags, from `Core45\HCaptcha\HCaptchaServiceProvider::bootPublishing()`:

```bash
php artisan vendor:publish --tag=hcaptcha-config       # config/hcaptcha.php
php artisan vendor:publish --tag=hcaptcha-lang         # lang/vendor/hcaptcha/{locale}/hcaptcha.php
php artisan vendor:publish --tag=hcaptcha-views        # resources/views/vendor/hcaptcha/*.blade.php
php artisan vendor:publish --tag=hcaptcha-migrations   # database/migrations/*_create_hcaptcha_verifications_table.php
```

Only run `php artisan migrate` if you intend to turn on the audit trail (`hcaptcha.logging.enabled`). Nothing else in the package needs a database table — verification talks to hCaptcha over HTTP and returns a value object. The package's own audit migration is not even registered unless `hcaptcha.logging.enabled` or `hcaptcha.logging.migrations` is on — see [Audit migrations](docs/audit-trail.md#audit-migrations).

## Getting your hCaptcha keys

You need two values: a **sitekey**, which is public and rendered into your HTML, and a **secret key**, which is private and only ever sent server-to-server. They live in different places in the dashboard, which is the usual source of confusion.

### 1. Create an account

Sign up at [dashboard.hcaptcha.com/signup](https://dashboard.hcaptcha.com/signup). The free tier covers ordinary form protection and needs no card.

### 2. Create a sitekey — *Sites* tab

Go to [dashboard.hcaptcha.com/sites](https://dashboard.hcaptcha.com/sites) and add a new site. Enter the hostnames the widget will be served from. You get a sitekey that looks like a UUID:

```dotenv
HCAPTCHA_SITEKEY=20000000-ffff-ffff-ffff-000000000002
```

### 3. Copy your secret key — *Settings* tab

The secret key is on [dashboard.hcaptcha.com/settings](https://dashboard.hcaptcha.com/settings), not on the sitekey page. **It belongs to your account, not to an individual sitekey** — every sitekey you create verifies against the same secret. It looks like a hex string:

```dotenv
HCAPTCHA_SECRET=0x1234567890abcdef1234567890abcdef12345678
```

Treat it like a password: server-side only, never committed, never rendered.

### 4. Turn on the domain allowlist

In the sitekey's settings, enable the **domain allowlist** and list your hostnames. Listed hostnames automatically cover their subdomains.

This matters more than it looks. Per hCaptcha's own documentation the allowlist is *disabled by default*, so a new sitekey will verify tokens solved on **any** domain — including an attacker's page using your public sitekey. This package's `hostnames` check defends against exactly that from the application side, but the two layers are independent and you want both.

### 5. Set the keys and you are done

```dotenv
HCAPTCHA_SITEKEY=your-sitekey
HCAPTCHA_SECRET=your-secret-key
```

No `HCAPTCHA_HOSTNAMES` needed if the form is served from `APP_URL`'s host — that is the default. Set it when the form lives on another host (a proxy, an alternate domain, a staging alias), or genuine submissions are rejected as `hostname-mismatch`.

### Testing without an account

hCaptcha publishes a keypair that always verifies successfully, so you can build and run tests before signing up:

```dotenv
HCAPTCHA_SITEKEY=10000000-ffff-ffff-ffff-000000000001
HCAPTCHA_SECRET=0x0000000000000000000000000000000000000000
```

The token it produces is `10000000-aaaa-bbbb-cccc-000000000001`. These accept everything, so never let them reach production — the package cannot tell them apart from real credentials.

## Migrating from thinhbuzz/laravel-h-captcha

Swap `buzz/laravel-h-captcha` for this package and keep your code — the `Captcha` facade, the `captcha` rule, and your `.env` keys all keep working. See [Migrating from thinhbuzz/laravel-h-captcha](docs/migrating-from-thinhbuzz.md) for what changes and the one hostname-check surprise to watch for.

## Configuration

Every key in `config/hcaptcha.php`:

| Key | Env var | Default |
| --- | --- | --- |
| `sitekey` | `HCAPTCHA_SITEKEY` | `null` |
| `secret` | `HCAPTCHA_SECRET` | `null` |
| `profiles` | — | `[]` |
| `endpoint` | `HCAPTCHA_ENDPOINT` | `https://api.hcaptcha.com/siteverify` |
| `timeout` | `HCAPTCHA_TIMEOUT` | `10` |
| `retries` | `HCAPTCHA_RETRIES` | `0` |
| `max_token_length` | `HCAPTCHA_MAX_TOKEN_LENGTH` | `8192` |
| `fail_open` | `HCAPTCHA_FAIL_OPEN` | `false` |
| `send_sitekey` | `HCAPTCHA_SEND_SITEKEY` | `true` |
| `hostnames` | `HCAPTCHA_HOSTNAMES` | host of `APP_URL` |
| `hostnames_strict` | `HCAPTCHA_HOSTNAMES_STRICT` | `false` |
| `hostnames_required` | `HCAPTCHA_HOSTNAMES_REQUIRED` | `true` |
| `max_score` | `HCAPTCHA_MAX_SCORE` | `null` |
| `field` | — | `h-captcha-response` |
| `locale` | — | `null` (resolves the app locale at render time) |
| `attributes` | — | `['theme' => 'light', 'size' => 'normal']` |
| `script.enabled` | — | `true` |
| `script.url` | — | `https://js.hcaptcha.com/1/api.js` |
| `logging.enabled` | `HCAPTCHA_LOGGING` | `false` |
| `logging.table` | — | `hcaptcha_verifications` |
| `logging.connection` | `HCAPTCHA_LOGGING_CONNECTION` | `null` |
| `logging.store_ip` | `HCAPTCHA_LOG_IP` | `false` |
| `logging.store_user_agent` | `HCAPTCHA_LOG_USER_AGENT` | `false` |
| `logging.store_url` | `HCAPTCHA_LOG_URL` | `false` |
| `logging.log_missing_token` | `HCAPTCHA_LOG_MISSING_TOKEN` | `false` |
| `logging.log_oversized_token` | `HCAPTCHA_LOG_OVERSIZED_TOKEN` | `false` |
| `logging.retention_days` | `HCAPTCHA_RETENTION_DAYS` | `90` |
| `logging.migrations` | `HCAPTCHA_LOGGING_MIGRATIONS` | `null` (follows `logging.enabled`) |

`sitekey` and `secret` both default to `null` on purpose: a missing secret throws (`MissingSecretException`) rather than silently rejecting every visitor, and a missing sitekey throws when a widget or field asks for one (`MissingSitekeyException`), or degrades to rendering nothing when the caller checks `HCaptcha::configured()` first.

`retries` counts additional attempts after the first and defaults to `0`.

`max_token_length` rejects an oversized token without making an HTTP call. hCaptcha tokens run a few hundred to a few thousand characters, so without a cap an unauthenticated request could have the package proxy a huge body to hCaptcha while holding a PHP worker for the whole timeout.

### Your sitekey is public — this is why `hostnames` matters

**Your sitekey is public. It is sitting in your page's HTML.** An attacker can embed *your* sitekey on *their own* page, solve the challenge there themselves — or buy a solved token from a captcha farm — and post that genuine token to your form. `siteverify` answers `success: true`, because the token really was solved against your sitekey. `send_sitekey` cannot catch this: the sitekey matches, so there is nothing to flag.

Two layers limit that attack, and you want both.

1. **The domain allowlist on the sitekey in the hCaptcha dashboard.** This is the authoritative one, and it is *off by default* for new sitekeys. Turn it on.
2. **This package's `hostnames` check**, on by default and derived from `APP_URL`. hCaptcha documents the reported hostname as browser-derived and "not suitable for authentication", and says it may come back as `not-provided` under load. Treat this check as a policy that catches careless misuse, not as proof of origin.

Set `HCAPTCHA_HOSTNAMES` to a comma-separated list for multi-domain installs. An allowlist that resolves to nothing — `HCAPTCHA_HOSTNAMES` unset and `APP_URL` without a scheme, for example — is rejected by default (`rejectedBy: hostname-allowlist-empty`), because an empty list is the one thing standing between a public sitekey and a token solved on somebody else's page; silently skipping the check would be the worst default. Set `HCAPTCHA_HOSTNAMES_REQUIRED=false` to get the old behaviour instead, where an empty list skips the check and logs an `error` once per process. A response whose hostname is missing or `not-provided` passes with a `warning`; set `HCAPTCHA_HOSTNAMES_STRICT=true` to reject those instead, with `rejectedBy: hostname-unknown`.

Applications whose domains live in a database, rather than in config, can supply the allowlist at runtime instead of through `HCAPTCHA_HOSTNAMES` — see [Supplying hostnames from your own source](#supplying-hostnames-from-your-own-source) below.

`max_score` is a **risk** score, the inverse of reCAPTCHA v3: higher means more bot-like, so this is a ceiling, not a floor, and it only applies to Enterprise accounts that return a `score` at all.

hCaptcha's documented test keys always verify successfully and are safe for local development and CI:

```
sitekey: 10000000-ffff-ffff-ffff-000000000001
secret:  0x0000000000000000000000000000000000000000
token:   10000000-aaaa-bbbb-cccc-000000000001
```

Config is intentionally free of container calls (`app()`, `trans()`, etc.) — `php artisan config:cache` evaluates the file once and freezes the result, so the widget locale is resolved at render time by `HCaptchaManager::locale()` instead.

### Supplying hostnames from your own source

`HCAPTCHA_HOSTNAMES` is enough for a single-domain site. An application whose domains live somewhere else — a multi-tenant platform where a shop's domain is a row in the database, and adding one in an admin panel should make that domain captcha-valid immediately, with no env edit and no deploy — binds its own `Core45\HCaptcha\Contracts\HostnameProvider` instead:

```php
namespace Core45\HCaptcha\Contracts;

interface HostnameProvider
{
    /**
     * @return list<string>
     */
    public function hostnames(): array;
}
```

An implementation reading from an Eloquent model, cached so every verification does not hit the database:

```php
use Core45\HCaptcha\Contracts\HostnameProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

class TenantDomainHostnameProvider implements HostnameProvider
{
    public function __construct(private Cache $cache)
    {
    }

    public function hostnames(): array
    {
        return $this->cache->remember(
            'hcaptcha.tenant-hostnames',
            now()->addMinute(),
            fn () => Shop::query()->pluck('domain')->all(),
        );
    }
}
```

Register the binding with `scoped()`, not `singleton()`, in a service provider's `register()`:

```php
$this->app->scoped(HostnameProvider::class, TenantDomainHostnameProvider::class);
```

`scoped()` matters under Octane: a `singleton()` binding would resolve once per worker boot and then hand out that first request's allowlist to every later request on the same worker. This package's own `HttpVerifier` is bound the same way, and never caches the resolved hostnames itself — the provider is called fresh on every `verify()`, so it is the provider's own job to decide whether and how long to cache, as in the example above.

**Resolution order.** On each verification, the bound provider is asked first. If it returns `[]`, the verifier falls back to `hcaptcha.hostnames` (`HCAPTCHA_HOSTNAMES` / the host of `APP_URL`). If that is also empty, the request is rejected — see the `hostnames_required` behaviour above. In other words: returning `[]` from your provider does **not** disable the check or open it up; it means "I have nothing to add", and the config fallback and the empty-list rejection still apply underneath it. A provider that genuinely wants to allow every hostname has to say so explicitly by turning `hostnames_required` off, not by returning an empty array.

Every hostname source — the provider, `HCAPTCHA_HOSTNAMES`, config — is normalized the same way before comparison (lowercased, trimmed, blank entries dropped), so a domain saved with stray casing or whitespace still matches.

## The widget

```blade
<x-hcaptcha />
<x-hcaptcha theme="dark" size="compact" />
<x-hcaptcha sitekey="10000000-ffff-ffff-ffff-000000000001" locale="es" id="contact-captcha" />
<x-hcaptcha :options="['size' => 'invisible']" />
```

Attributes the component (`Core45\HCaptcha\View\Components\HCaptcha`) accepts:

| Attribute | Type | Purpose |
| --- | --- | --- |
| `sitekey` | `?string` | Override the configured sitekey for this widget |
| `theme` | `?string` | `light`, `dark`, etc. — merged into `data-theme` |
| `size` | `?string` | `normal`, `compact`, `invisible` — merged into `data-size` |
| `locale` | `?string` | Override the widget language for this instance |
| `id` | `?string` | Override the generated widget DOM id |
| `script` | `bool` | Whether this instance renders the `api.js` tag (default `true`) |
| `model` | `?string` | Livewire property to bind the token into, e.g. `captchaToken` |
| `options` | `array<string, scalar\|null>` | Extra `data-*` widget options passed through verbatim |

### Custom callbacks

```blade
<x-hcaptcha :options="['callback' => 'app.onCaptcha', 'error-callback' => 'app.onCaptchaError']" />
```

`callback`, `expired-callback`, `chalexpired-callback`, `error-callback`, `open-callback` and `close-callback` name a global function (dotted paths resolve from `window`). The package publishes the token to the hidden input first, then calls yours.

### Invisible mode

```blade
<form method="post" action="/contact">
    <x-hcaptcha size="invisible" />
    <button type="submit">Send</button>
</form>
```

With `size="invisible"` nothing is shown until the form is submitted. The bootstrap intercepts the form's `submit`, runs `hcaptcha.execute()`, writes the token, and submits again — plain forms and `wire:submit` alike. A second submit while a challenge is pending is ignored. If the challenge fails or is closed, the status element shows `widget_error` and the visitor can submit again. To run it yourself: `window.core45HCaptcha.execute(id)` returns a Promise resolving to the token.

### Content Security Policy

```php
// AppServiceProvider::boot()
\Core45\HCaptcha\HCaptchaManager::nonceUsing(fn () => \Illuminate\Support\Facades\Vite::cspNonce());
```

Both script tags carry `nonce="…"` when a nonce resolves: from `HCaptchaManager::nonceUsing()`, else from `Vite::cspNonce()` when your app calls `Vite::useCspNonce()`. `HCaptchaManager::cspDirectives()` returns the origins hCaptcha requires for `script-src`, `frame-src`, `style-src` and `connect-src` (`https://hcaptcha.com https://*.hcaptcha.com` — never a specific asset subdomain).

Every widget always renders in hCaptcha's *explicit* mode. `api.js` is loaded with `render=explicit&onload=core45HCaptchaOnLoad`, and the bootstrap script (`resources/js/bootstrap.js`, emitted through `HCaptcha::bootstrapScript()`) renders each `[data-hcaptcha-explicit]` container as soon as it exists. Widgets are discovered with a `MutationObserver`, so a widget inserted after page load — by Livewire, Alpine, a modal, or plain JavaScript — renders on its own, and a widget removed from the page is dropped from the registry. Both script tags carry `data-navigate-once`, so `wire:navigate` never reloads the SDK.

Widget ids are deterministic: `hcaptcha-page-1`, `hcaptcha-page-2`, … on a plain page and `hcaptcha-<livewire-id>-1` inside a Livewire component, so a re-render binds to the same hidden input. Pass `id="…"` to choose your own. The hidden input is `<id>-response` and a `<p id="<id>-status" role="status">` live region shows the widget's pending/error text (`hcaptcha::hcaptcha.widget_pending`, `widget_error`).

hCaptcha's own auto mode (scanning `.h-captcha` elements on `DOMContentLoaded`) is not supported and cannot be enabled. It injects its own response field outside this package's control, loses the token on any DOM patch, and bypasses the hidden input the package tracks — which produced `missing-input-response` even when the visitor had completed the challenge.

Widget attribute names (from `options`, `theme`, `size`, etc.) are validated, not merely escaped — `HCaptchaManager::normaliseAttribute()` throws `InvalidArgumentException` on a name that isn't letters/digits/hyphens, because a key such as `data-x onmouseover=alert(1)` would otherwise render as a second, live attribute. The JavaScript identifiers the package emits into `<script>` tags (`core45HCaptchaOnLoad`, `core45HCaptcha`) are validated the same way.

If no sitekey is configured, the component renders nothing in production and a visible warning `<div>` when `app.debug` is true — it never throws mid-page. The verifier still fails closed regardless, so a form cannot be submitted past a misconfigured widget.

The widget's inline error paragraph is guarded with `@if (isset($errors) && ...)`, since `$errors` is only shared into the view by the `web` middleware group — rendering the widget outside it (an API-only route, a non-web response) does not fail, it simply shows no inline error.

## Validating

### The `HCaptcha` rule object (preferred)

```php
$request->validate([
    'h-captcha-response' => [new \Core45\HCaptcha\Rules\HCaptcha],
]);
```

Do not pair this rule with `required`. The rule itself is implicit (`public bool $implicit = true`) so it already runs — and reports its own "please complete the captcha" message — on a missing token. Adding `required` only races it: Laravel's generic "required" message wins and the package's specific message is suppressed.

It calls `$fail($result->messageKey())->translate()`, and `VerificationResult::messageKey()` distinguishes four outcomes:

- `hcaptcha::hcaptcha.missing` — no token was submitted at all
- `hcaptcha::hcaptcha.unavailable` — hCaptcha could not be reached and the package failed closed
- `hcaptcha::hcaptcha.expired` — the token was already spent (`already-seen-response`) or expired (`expired-input-response`)
- `hcaptcha::hcaptcha.failed` — anything else hCaptcha rejected — including a malformed token (`invalid-input-response`)

### The `hcaptcha` string rule, and the `captcha` alias

```php
$request->validate([
    'h-captcha-response' => 'hcaptcha',
]);
```

Do not pair this with `required` either — it is registered via `Validator::extendImplicit()`, so it already fires and reports `hcaptcha::hcaptcha.missing` on an empty or absent token.

`captcha` is registered as an identical alias, for anyone migrating from `buzz/laravel-h-captcha`. Unlike the rule object, the string rule can only carry one message per failed attempt — it still resolves the correct translation key via `VerificationResult::messageKey()`, but cannot report it back through more than the standard replacer. The two forms differ only in that granularity, not in whether they fire on a missing token — both are implicit.

## Middleware

```php
Route::post('/contact', ContactController::class)->middleware('hcaptcha');
Route::post('/contact', ContactController::class)->middleware('hcaptcha:my-field');
```

The middleware also accepts an optional second parameter, the sitekey the widget was rendered with (`hcaptcha:h-captcha-response,<sitekey>`), so it shares a verdict with a rule constructed with the same `sitekey:`. An optional third parameter names a [credential profile](#named-credential-profiles) (`hcaptcha:h-captcha-response,,marketing` — the empty slot is the sitekey, which the profile supplies). Both come from the route definition, which is server code; neither is ever read from the request.

Registered under the alias `hcaptcha` (`Core45\HCaptcha\Http\Middleware\VerifyHCaptcha`). It reads the token from `config('hcaptcha.field')` unless a field name is passed as middleware parameter, **verifies every request it sees — it no longer skips `GET`/`HEAD`/`OPTIONS`** — and on failure throws `Illuminate\Validation\ValidationException` with the same translated message key the rule object would produce — a 422 with a message bag, or a redirect-with-errors for a normal form post, never a 500. Safe to stack with the validation rule or the Filament field: the verifier's per-request memoization means the token is still spent only once.

**Put this middleware on the state-changing route only — never on a route group that also serves the GET that renders the form.** Because it checks every method, a group covering both the form's GET and its POST would fail the GET too. The old allowlist that skipped GET/HEAD/OPTIONS was removed because it was spoofable: Symfony's `_method` override refuses only `GET`, `HEAD`, `CONNECT` and `TRACE`, so a POST carrying `_method=OPTIONS` reported as `OPTIONS` and skipped the check while the controller still received the full POST body. A route that fails loudly in development beats one that can be silently bypassed in production.

### Rate limiting

This package does no throttling of its own, and does not claim to — the middleware verifies a token, it does not limit how many times someone can submit. Pair it with Laravel's rate limiter on the guarded route:

```php
Route::post('/contact', ContactController::class)->middleware(['throttle:10,1', 'hcaptcha']);
```

## Livewire

```blade
<x-hcaptcha model="captchaToken" />
```

```php
public string $captchaToken = '';

public function submit()
{
    $this->validate(['captchaToken' => [new \Core45\HCaptcha\Rules\HCaptcha]]);

    // ...
}
```

The widget wraps itself in `wire:ignore` so a Livewire re-render never replaces the iframe hCaptcha owns; the bootstrap re-renders widgets itself. Tokens are single-use, so after every verification — accepted or rejected, even when another field failed — the rule dispatches `core45HCaptcha:reset` with the validated field name and the bootstrap resets exactly that widget inside your component. You no longer call `$this->dispatch('core45HCaptcha:reset')` yourself; if you do, an event without `field` resets every widget, and `{ id }` resets one.

JavaScript entry points on `window.core45HCaptcha`: `render(el)`, `renderAll()`, `reset(id?)`, `execute(id)`, `markReady()`, `widgets`, `container(id)`.

## Filament

```php
use Core45\HCaptcha\Filament\Forms\Components\HCaptcha;

HCaptcha::make() // field name defaults to config('hcaptcha.field')
    ->theme('dark')
    ->size('compact'),
```

> Note: the Filament field ships in the same release as the rest of this package.

The field calls `->markAsRequired()` (an asterisk only — a UI cue) and carries the `HCaptcha` rule object out of the box; it does not call `->required()`, since the rule is already implicit and a `required()` rule would only compete with it for which message wins. It also calls `->dehydrated(false)` deliberately — the token is single-use and must never be persisted onto the model — but validation still runs against the raw form state before dehydration strips the value, so the captcha is still enforced on save.

When no usable sitekey resolves the field renders no widget (a debug-only notice, like the Blade component) but stays in validation, so the form still fails closed. Widget ids are scoped to the Livewire component, so two forms with the same state path on one page do not collide: the id is `hcaptcha-<livewire-id>-<dom-safe-state-path>`, e.g. `hcaptcha-<livewire-id>-data-h-captcha-response` for a field bound to `data.h-captcha-response`.

## Named credential profiles

One application can serve several sites, each with its own hCaptcha sitekey and secret. A named profile under `hcaptcha.profiles` holds such a pair, falling back to the global `sitekey`/`secret` for whichever half it leaves unset:

```php
// config/hcaptcha.php
'profiles' => [
    'marketing' => [
        'sitekey' => env('HCAPTCHA_MARKETING_SITEKEY'),
        'secret' => env('HCAPTCHA_MARKETING_SECRET'),
    ],
],
```

Select it wherever the widget renders and wherever the token is verified — the two must name the same profile, or verification checks the token against a secret it was never minted for:

```blade
<x-hcaptcha profile="marketing" />
```

```php
HCaptcha::make()->profile('marketing'),                          // Filament
new \Core45\HCaptcha\Rules\HCaptcha(profile: 'marketing'),        // validation rule
```

```php
Route::post('/signup', SignupController::class)
    ->middleware('hcaptcha:h-captcha-response,,marketing');       // note the empty sitekey slot
```

**The profile name must always come from server code, never from request input.** A visitor who could choose the profile could choose which secret vouches for their token — exactly what `HCaptchaManager::profileSitekey()`, `profileCredentials()` and `profileNames()` (also on the `HCaptcha` facade) are built to prevent by keeping the choice out of the request.

The profile is part of the memo key (`VerificationContext::credentialKey()`), alongside field and action, so a verdict obtained for one profile can never vouch for another, and the middleware and rule can still share one HTTP call when both name the same profile.

An unknown profile name throws `InvalidArgumentException`, both when the widget resolves a sitekey for it and when verification resolves credentials for it — a typo in a profile name is treated as a bug to surface, not as input to tolerate.

## Verification events

`Core45\HCaptcha\Events\VerificationCompleted` fires once per verification that actually reached hCaptcha — after the audit row, if the audit trail is enabled. It carries:

| Property | Type | Meaning |
| --- | --- | --- |
| `result` | `VerificationResult` | The verdict |
| `context` | `VerificationContext` | Field, action, sitekey and profile the token was checked against |
| `tokenHash` | `string` | SHA-256 of the token. The raw token is never carried. |

Plus `passed(): bool`, a shortcut for `$event->result->passed()`.

```php
use Core45\HCaptcha\Events\VerificationCompleted;
use Illuminate\Support\Facades\Event;

Event::listen(function (VerificationCompleted $event): void {
    if ($event->result->isConfigurationError()) {
        // alert — this is a deployment problem, not a visitor problem
    }

    Metrics::increment($event->passed() ? 'hcaptcha.passed' : 'hcaptcha.failed');
});
```

It does **not** fire for a memoized repeat verdict — one solved token checked by both the middleware and the rule is one verification, and firing twice would over-report — nor for a submission carrying no token, nor for one over `max_token_length`: neither reaches hCaptcha, and both are free for an unauthenticated caller to trigger, which is what `logging.log_missing_token` and `logging.log_oversized_token` exist to opt into separately.

## Verifying by hand

```php
use Core45\HCaptcha\Facades\HCaptcha;

$result = HCaptcha::verify($request->input('h-captcha-response'), $request->ip());

if ($result->passed()) {
    // ...
}
```

`VerificationResult` (`Core45\HCaptcha\Support\VerificationResult`) is a readonly value object:

| Property | Type | Meaning |
| --- | --- | --- |
| `success` | `bool` | hCaptcha's own verdict — compared with `=== true` against the decoded response, not cast, so a malformed 200 body cannot turn into a pass. Never rewritten by this package. |
| `accepted` | `bool` | This package's final answer, after the hostname and score assertions and the fail-open policy. What `passed()` returns. |
| `hostname` | `?string` | Hostname the token was reported for. Browser-derived; may be `not-provided`. |
| `challengeTs` | `?Carbon` | Challenge timestamp |
| `score` | `?float` | Risk score (Enterprise accounts only) |
| `scoreReasons` | `list<string>` | Score explanation codes |
| `errorCodes` | `list<string>` | Error codes from hCaptcha, plus any local rejection reason appended |
| `credit` | `?bool` | Whether the request counted against your account |
| `serviceUnavailable` | `bool` | hCaptcha could not be reached or answered unusably |
| `rejectedBy` | `?string` | `hostname-mismatch`, `hostname-unknown` or `score-too-high` when a local assertion rejected an otherwise genuine token |

Helper methods: `passed()`, `failed()`, `hasErrorCode(string $code)`, `tokenAlreadyUsed()`, `tokenExpired()`, `tokenMalformed()`, `tokenMissing()`, `isConfigurationError()`, `messageKey()`, plus `toArray()`/`jsonSerialize()`.

`success` and `accepted` differ in exactly two situations. A genuine token minted for the wrong hostname, or scoring above `max_score`, is `success: true` and `accepted: false` with `rejectedBy` set. An outage under `fail_open` is `success: false` and `accepted: true` with `serviceUnavailable` set.

Error codes follow [hCaptcha's siteverify table](https://docs.hcaptcha.com/#siteverify-error-codes-table). A spent token is `already-seen-response`; `token-already-used` was this package's own 1.x name for it and is still recognised by `tokenAlreadyUsed()`. A token over `max_token_length` gets the package-specific code `token-too-long` and never reaches hCaptcha.

## Upgrading from 1.x

2.0.0 changes behaviour in ten places — mostly correctness fixes around `VerificationResult::success` vs `accepted`, memoization scoping, and error codes. See [Upgrading from 1.x](docs/upgrading.md) for the full list before moving a 1.x install to 2.0.0.

## Audit trail

An optional audit trail logs every verification attempt to `hcaptcha_verifications`, stores a SHA-256 hash of the token (never the raw token), and ships a pruning command. See [Audit trail](docs/audit-trail.md) for what's stored, the PII toggles, and how the migration is registered.

## Fail-open vs fail-closed

By default (`fail_open` = `false`), an hCaptcha outage — an unreachable endpoint, a non-2xx response, or an unparseable body — fails **closed**: `VerificationResult::unavailable()` reports `accepted: false`, so the form is rejected. Setting `HCAPTCHA_FAIL_OPEN=true` accepts the submission instead: the result is `accepted: true` with `success: false`, `error-codes: ['service-unavailable']` and `serviceUnavailable: true`, so you can still detect it happened, and the hostname and score assertions are skipped because there is no response to assert against. A provider rejection is never turned into acceptance by fail-open. The outage itself is always logged via the application logger (`warning`), regardless of which way it fails.

## Known limitation: repeated invocations inside one Livewire request

A verdict is memoized per `(token, field, action, expected sitekey)`, where the action is the executing Livewire component. That bounds a solved token to the field, component and sitekey it was solved for, so one captcha cannot authorise a *different* field, a *different* component, or a *different* sitekey in the same request.

It does **not** bound how many times the *same* field on the *same* component is submitted within one request. A client that replays the same component snapshot repeatedly in one batch can have a single solved captcha accepted by every replay — they share the same memo key, which is indistinguishable from the legitimate case of the rule and the middleware both checking that field.

Closing this properly needs a per-invocation boundary, which the package cannot see from inside a validation rule. Until then:

- put `throttle` on the guarded route (see [Rate limiting](#rate-limiting)) — this is the effective mitigation, which is why it is a recommendation and not an optional extra;
- keep captcha-guarded Livewire actions idempotent where you can, so a replay costs nothing;
- for a high-value action, enable the audit trail and reject a `token_hash` you have already accepted.

Stated plainly because the alternative — implying one captcha equals one submission — would be untrue.

## Content Security Policy

hCaptcha needs a few directives allowed, and widgets support nonces via `HCaptchaManager::nonceUsing()`. See [Content Security Policy](docs/content-security-policy.md) for the directive list, nonce setup, and a Livewire-specific `'unsafe-eval'` caveat.

## Translations

22 locales ship in `resources/lang`, each with five keys. See [Translations](docs/translations.md) for the full list and how to publish and customize them.

## Testing

Fake the HTTP call in a consuming application's tests — nothing in this package needs a live hCaptcha account to test against:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.hcaptcha.com/*' => Http::response(['success' => true, 'hostname' => 'example.test']),
]);

$this->post('/contact', ['h-captcha-response' => 'any-token'])
    ->assertSessionHasNoErrors();

Http::assertSentCount(1); // the memoization guarantee: one token per field, one HTTP call
```

Browser tests in a consuming app can point `hcaptcha.script.url` at the package's stub, `resources/js/fake-api.js` (served by a route of your own), which implements `render`, `execute`, `reset` and `getResponse` without the network and exposes `window.__fakeHCaptcha`.

### `HCaptcha::fake()`

For tests that would rather not fake the HTTP layer at all:

```php
use Core45\HCaptcha\Facades\HCaptcha;

$hcaptcha = HCaptcha::fake(); // accepts every token from here on

$this->post('/contact', ['h-captcha-response' => \Core45\HCaptcha\Testing\FakeVerifier::TOKEN])
    ->assertSessionHasNoErrors();

$hcaptcha->assertVerifiedFor('h-captcha-response');
```

`HCaptcha::fake(bool $passes = true): FakeVerifier` binds a `Core45\HCaptcha\Testing\FakeVerifier` that answers without touching the network. `FakeVerifier` exposes:

- `pass()` — accept every token from here on.
- `fail(string $errorCode = 'invalid-input-response')` — reject every token, with a chosen error code (a spent token, for instance, is `already-seen-response`).
- `respondWith(Closure|VerificationResult $answer)` — for anything the two switches above don't cover: an outage, a rejected hostname, a score. The closure receives `(?string $token, VerificationContext $context)`.
- `verifications()` — every call made through the fake, in order.
- `assertVerified(?Closure $callback = null)`, `assertVerifiedFor(string $field)`, `assertVerifiedTimes(int $times)`, `assertNothingVerified()`.

A missing token is still answered as missing, whatever the fake was told to do — a test that forgets to fill the field sees the real "please complete the captcha" outcome, not a pass.

While the fake is bound, every widget — the Blade component and the Filament field alike — renders `resources/views/fake.blade.php` instead of hCaptcha's real widget: a hidden input pre-filled with `FakeVerifier::TOKEN`, so a plain form post passes with no JavaScript at all (Filament's field has no `name` on that input — its Livewire state-path binding carries the value instead), plus a `[data-fake-hcaptcha-checkbox]` button for a browser test to click, which fills the same input via an `input`/`change` event for Livewire and Filament to pick up.

That button's click handler is an inline `onclick` attribute. A browser test running under a strict `script-src` (no `'unsafe-inline'`) must either allow inline scripts for the test, or drive the hidden input directly instead of clicking the button.

## Diagnostics

```bash
php artisan hcaptcha:doctor
```

Reports what an installation's configuration will actually do, without making a network call and without ever printing the secret. It checks:

- **Credentials** — whether `hcaptcha.sitekey` / `hcaptcha.secret` resolve to something usable, and warns if either is hCaptcha's public always-pass test pair outside a `local` or `testing` environment.
- **Profiles** — every name in `hcaptcha.profiles` resolves both a sitekey and a secret (falling back to the global pair where the profile leaves a half unset).
- **Hostnames** — what `hcaptcha.hostnames` resolves to, and whether `hostnames_strict` is on.
- **Audit trail** — if `logging.enabled`, whether the `hcaptcha_verifications` table exists, and warns when a PII toggle (`store_ip`, `store_user_agent`, `store_url`) is on.

Exits `SUCCESS` (`0`) when nothing is wrong, `FAILURE` (`1`) otherwise — usable as a deployment gate.

## Versioning & License

Follows [Semantic Versioning](https://semver.org/). Licensed under the [MIT License](LICENSE.md).

See [SECURITY.md](SECURITY.md) for reporting a vulnerability, and [docs/support-policy.md](docs/support-policy.md) for which versions receive fixes and what counts as this package's public API.
