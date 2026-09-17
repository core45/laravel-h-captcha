# core45/h-captcha

hCaptcha for Laravel: a typed verifier, a Blade widget, a Livewire-aware explicit-render script, a Filament form field, a validation rule, route middleware, and an optional database audit trail. It uses Laravel's own `Http` client, not Guzzle directly.

## Why this exists

`buzz/laravel-h-captcha` pins `guzzlehttp/guzzle` `6.*|7.*`. It cannot be installed alongside Guzzle 8, which is what current Laravel projects use. Rather than downgrade Guzzle for an unmaintained wrapper, this package talks to hCaptcha through Laravel's `Http` facade, so it has no Guzzle version constraint of its own.

The other problem it solves is more subtle. **hCaptcha tokens are single-use.** A form can be guarded by more than one entry point at once — a validation rule and route middleware, or a Filament field that validates on update and again on submit — and if each one calls `siteverify` independently, the first call spends the token and every later call gets back `token-already-used`. The visitor sees a bogus failure they did nothing to cause.

The fix is one `Verifier` implementation (`Core45\HCaptcha\Support\HttpVerifier`) that every entry point routes through, memoizing the verdict per request by `hash('sha256', $token)` **and** a scope. The rule, the middleware, the Blade component's field, and the Filament field are all thin callers of the same verifier — the token is spent exactly once per field, no matter how many places check that field.

The scope exists because the memoization is a safety mechanism, not a cache. `Verifier::verify()` takes a third parameter, `verify(?string $token, ?string $clientIp = null, ?string $scope = null)` — the validated attribute (for the rule) or the field name (for the middleware). Keyed on the token alone, one solved captcha would authorise every consumer of it in the request, and Livewire processes up to 200 components in a single HTTP request with nothing flushing scoped container bindings between them — an unscoped memo would let a captcha solved for one field silently pass for every other field in that batch. Two checks of the *same* field (the rule and the middleware guarding it) still collapse to one HTTP call; two *different* fields do not, and the second one hits hCaptcha for real.

## Installation

```bash
composer require core45/h-captcha
```

Publish tags, from `Core45\HCaptcha\HCaptchaServiceProvider::bootPublishing()`:

```bash
php artisan vendor:publish --tag=hcaptcha-config       # config/hcaptcha.php
php artisan vendor:publish --tag=hcaptcha-lang         # lang/vendor/hcaptcha/{locale}/hcaptcha.php
php artisan vendor:publish --tag=hcaptcha-views        # resources/views/vendor/hcaptcha/*.blade.php
php artisan vendor:publish --tag=hcaptcha-migrations   # database/migrations/*_create_hcaptcha_verifications_table.php
```

Only run `php artisan migrate` if you intend to turn on the audit trail (`hcaptcha.logging.enabled`). Nothing else in the package needs a database table — verification talks to hCaptcha over HTTP and returns a value object.

## Configuration

Every key in `config/hcaptcha.php`:

| Key | Env var | Default |
| --- | --- | --- |
| `sitekey` | `HCAPTCHA_SITEKEY` | `null` |
| `secret` | `HCAPTCHA_SECRET` | `null` |
| `endpoint` | `HCAPTCHA_ENDPOINT` | `https://api.hcaptcha.com/siteverify` |
| `timeout` | `HCAPTCHA_TIMEOUT` | `10` |
| `retries` | `HCAPTCHA_RETRIES` | `1` |
| `max_token_length` | `HCAPTCHA_MAX_TOKEN_LENGTH` | `8192` |
| `fail_open` | `HCAPTCHA_FAIL_OPEN` | `false` |
| `send_sitekey` | `HCAPTCHA_SEND_SITEKEY` | `true` |
| `hostnames` | `HCAPTCHA_HOSTNAMES` | host of `APP_URL` |
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
| `logging.retention_days` | `HCAPTCHA_RETENTION_DAYS` | `90` |

`sitekey` and `secret` both default to `null` on purpose: a missing secret throws (`MissingSecretException`) rather than silently rejecting every visitor, and a missing sitekey throws when a widget or field asks for one (`MissingSitekeyException`), or degrades to rendering nothing when the caller checks `HCaptcha::configured()` first.

`max_token_length` rejects an oversized token without making an HTTP call. hCaptcha tokens run a few hundred to a few thousand characters, so without a cap an unauthenticated request could have the package proxy a huge body to hCaptcha while holding a PHP worker for the whole timeout.

### Your sitekey is public — this is why `hostnames` matters

**Your sitekey is public. It is sitting in your page's HTML.** An attacker can embed *your* sitekey on *their own* page, solve the challenge there themselves — or simply buy a solved token from a captcha farm — and post that genuine token to your form. `siteverify` answers `success: true`, because the token really was solved against your sitekey; it just reports `hostname: attacker.example`. `send_sitekey` cannot catch this: the sitekey matches, so there is nothing for it to flag as a mismatch. **The hostname check is the only defence against this attack.**

That is why `hostnames` now defaults to on rather than `null`: `env('HCAPTCHA_HOSTNAMES', parse_url((string) env('APP_URL'), PHP_URL_HOST))`. Set `HCAPTCHA_HOSTNAMES` to a comma-separated list for multi-domain installs. Setting it to an empty string disables the check — and that is logged as an `error` on every verification, since silently turning off the one defence against sitekey theft deserves a loud signal. Blank entries inside a configured list are filtered out too, so an empty string can never masquerade as "a restriction" that nothing can match. If the configured value resolves to no usable hostname at all, the check is logged as inactive rather than silently behaving as "no restriction".

**Also restrict the sitekey's hostnames in the hCaptcha dashboard.** That allowlist is off by default for new sitekeys. Skip it and both layers of the origin check are absent, and the attack above goes through unmodified.

`max_score` is a **risk** score, the inverse of reCAPTCHA v3: higher means more bot-like, so this is a ceiling, not a floor, and it only applies to Publisher/Pro accounts that return a `score` at all.

hCaptcha's documented test keys always verify successfully and are safe for local development and CI:

```
sitekey: 10000000-ffff-ffff-ffff-000000000001
secret:  0x0000000000000000000000000000000000000000
token:   10000000-aaaa-bbbb-cccc-000000000001
```

Config is intentionally free of container calls (`app()`, `trans()`, etc.) — `php artisan config:cache` evaluates the file once and freezes the result, so the widget locale is resolved at render time by `HCaptchaManager::locale()` instead.

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

Every widget always renders in hCaptcha's *explicit* mode — there is no auto mode to opt into. `api.js` is loaded with `render=explicit&onload=core45HCaptchaOnLoad`, and the bootstrap script (`resources/views/script.blade.php`) renders each `[data-hcaptcha-explicit]` container via `window.core45HCaptcha.render()`/`renderAll()` once the API is ready. This is what lets a widget inserted after the initial page load — by Livewire, Alpine, or a modal — still render.

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
- `hcaptcha::hcaptcha.expired` — the token was already spent (`token-already-used` / `invalid-input-response`)
- `hcaptcha::hcaptcha.failed` — anything else hCaptcha rejected

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

    $this->captchaToken = '';
    $this->dispatch('core45HCaptcha:reset');
}
```

The widget wraps itself in `wire:ignore` so a Livewire re-render never replaces the iframe hCaptcha owns mid-interaction — Livewire's morph would otherwise kill the challenge. The bootstrap script re-renders widgets itself, hooking `livewire:init` (`morphed`, `morph.added`) and `livewire:navigated`.

hCaptcha tokens are single-use, so **the widget must be reset after every submit, successful or not** — a spent token left in the form fails the next attempt with `token-already-used`. Reset via the JS entry points `script.blade.php` actually exposes on the global object:

- `window.core45HCaptcha` — the namespace, with `render(el)`, `renderAll()`, `reset(id?)`, `markReady()`, `widgets`, `container(id)`
- `window.core45HCaptcha.reset(id)` resets one widget by id; called with no argument it resets every widget on the page
- The `core45HCaptcha:reset` window event, dispatched with `{ detail: { id } }` (or no `id` to reset all), is the same reset path — dispatch it from Livewire with `$this->dispatch('core45HCaptcha:reset')` and listen for it in JS if you prefer an event-driven trigger over calling the function directly

## Filament

```php
use Core45\HCaptcha\Filament\Forms\Components\HCaptcha;

HCaptcha::make() // field name defaults to config('hcaptcha.field')
    ->theme('dark')
    ->size('compact'),
```

> Note: the Filament field ships in the same release as the rest of this package.

The field calls `->markAsRequired()` (an asterisk only — a UI cue) and carries the `HCaptcha` rule object out of the box; it does not call `->required()`, since the rule is already implicit and a `required()` rule would only compete with it for which message wins. It also calls `->dehydrated(false)` deliberately — the token is single-use and must never be persisted onto the model — but validation still runs against the raw form state before dehydration strips the value, so the captcha is still enforced on save.

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
| `success` | `bool` | hCaptcha's own verdict — compared with `=== true` against the decoded response, not cast, so a malformed 200 body cannot turn into a pass |
| `hostname` | `?string` | Hostname the token was minted for |
| `challengeTs` | `?Carbon` | Challenge timestamp |
| `score` | `?float` | Risk score (Publisher/Pro accounts only) |
| `scoreReasons` | `list<string>` | Score explanation codes |
| `errorCodes` | `list<string>` | Error codes from hCaptcha, plus any local rejection reason appended |
| `credit` | `?bool` | Whether the request counted against your account |
| `serviceUnavailable` | `bool` | hCaptcha could not be reached or answered unusably |
| `rejectedBy` | `?string` | `hostname-mismatch` or `score-too-high` when a local assertion rejected an otherwise genuine token |

Helper methods: `passed()`, `failed()`, `hasErrorCode(string $code)`, `tokenAlreadyUsed()`, `tokenMissing()`, `isConfigurationError()`, `messageKey()`, plus `toArray()`/`jsonSerialize()`.

`passed()` is deliberately not the same thing as hCaptcha saying yes: a genuine token minted for the wrong hostname, or scoring above `max_score`, is `success: true` from hCaptcha but `passed(): false` here, with `rejectedBy` set.

## Audit trail

Enable with `HCAPTCHA_LOGGING=true` (or `hcaptcha.logging.enabled`) and run the published migration. Every verification attempt — success or failure — writes one row to `hcaptcha_verifications` (configurable table/connection) via `Core45\HCaptcha\Support\VerificationLogger`, which never lets a logging failure fail the verification itself.

Stored: `success`, `token_hash` (SHA-256 of the token), `hostname`, `challenge_ts`, `score`, `error_codes` (JSON), `rejected_by`, plus `ip` / `user_agent` / `url` when their respective PII toggles are on, and timestamps.

**Deliberately not stored: the raw token.** It is a single-use credential — by the time it could be logged it is already spent, so keeping it would be a liability with no benefit. Only its SHA-256 hash is kept, which is enough to correlate a replay without being able to replay it yourself.

The PII toggles (`logging.store_ip`, `logging.store_user_agent`, `logging.store_url`) all default to `false`. When `store_url` is enabled it records the request path **without the query string** — a query string routinely carries signed-URL signatures, password-reset tokens, and email addresses, none of which belong in a 90-day audit table. Enable any of these only with a lawful basis.

`logging.log_missing_token` also defaults to `false`. A submission with no token at all costs an attacker nothing and never reaches hCaptcha, so recording it by default would let anyone inflate the audit table with free, unauthenticated POSTs. Turn it on only alongside route throttling.

`Core45\HCaptcha\Models\HCaptchaVerification` ships two scopes: `scopeFailed()` (`where('success', false)`) and `scopeForToken(string $tokenHash)` (attempts sharing a token hash — the fingerprint of a replay).

Prune old rows with the console command, and schedule it:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('hcaptcha:prune')->daily();
```

```bash
php artisan hcaptcha:prune                 # uses hcaptcha.logging.retention_days (default 90)
php artisan hcaptcha:prune --days=30
php artisan hcaptcha:prune --chunk=500
```

Setting `retention_days` to `0` disables pruning; the command exits with a warning and deletes nothing.

## Fail-open vs fail-closed

By default (`fail_open` = `false`), an hCaptcha outage — an unreachable endpoint, a non-2xx response, or an unparseable body — fails **closed**: `VerificationResult::unavailable()` reports `success: false`, so the form is rejected. Setting `HCAPTCHA_FAIL_OPEN=true` trades that away and accepts the submission instead, tagging the result with `error-codes: ['service-unavailable']` and `serviceUnavailable: true` so you can still detect it happened. The outage itself is always logged via the application logger (`warning`), regardless of which way it fails.

## Known limitation: repeated invocations inside one Livewire request

A verdict is memoized per `(token, scope)`, where scope is the validated attribute or field name. That bounds a solved token to the field it was solved for, so one captcha cannot authorise a *different* field in the same request.

It does **not** bound how many times the *same* field is submitted within one request. Livewire processes many components per HTTP request, so a client that replays the same component snapshot repeatedly in one batch can have a single solved captcha accepted by every replay — they all share the same scope, which is indistinguishable from the legitimate case of the rule and the middleware both checking that field.

Closing this properly needs a per-invocation boundary, which the package cannot see from inside a validation rule. Until then:

- put `throttle` on the guarded route (see [Rate limiting](#rate-limiting)) — this is the effective mitigation, which is why it is a recommendation and not an optional extra;
- keep captcha-guarded Livewire actions idempotent where you can, so a replay costs nothing;
- for a high-value action, enable the audit trail and reject a `token_hash` you have already accepted.

Stated plainly because the alternative — implying one captcha equals one submission — would be untrue.

## Translations

22 locales ship in `resources/lang`: `bg`, `cs`, `de`, `el`, `en`, `es`, `et`, `fi`, `fr`, `hr`, `hu`, `it`, `lt`, `lv`, `nl`, `pl`, `pt`, `sk`, `sl`, `sq`, `sv`, `uk`.

Publish and customize with:

```bash
php artisan vendor:publish --tag=hcaptcha-lang
```

Each locale file (`hcaptcha::hcaptcha.*`) has five keys:

| Key | Used for |
| --- | --- |
| `failed` | Token rejected by hCaptcha |
| `missing` | Form submitted with no token |
| `unavailable` | hCaptcha unreachable, failed closed |
| `expired` | Token already spent |
| `label` | Accessible label on the widget wrapper / Filament field |

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

## Versioning & License

Follows [Semantic Versioning](https://semver.org/). Licensed under the [MIT License](LICENSE.md).
