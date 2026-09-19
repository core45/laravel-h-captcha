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
- **Drop-in for `thinhbuzz/laravel-h-captcha`.** Same `.env` keys, same `Captcha` facade, same `captcha` rule — swap the package and keep your code. See [Migrating](#migrating-from-thinhbuzzlaravel-h-captcha).
- **22 languages**, a typed result object for when you want to know *why* a token failed, and an optional audit trail with a retention command.

Requires PHP 8.4 and Laravel 12 or 13.

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

Only run `php artisan migrate` if you intend to turn on the audit trail (`hcaptcha.logging.enabled`). Nothing else in the package needs a database table — verification talks to hCaptcha over HTTP and returns a value object.

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

```bash
composer remove buzz/laravel-h-captcha
composer require core45/laravel-h-captcha
```

No application code needs to change. The `Captcha` facade, the `captcha` validation rule, and your existing `.env` keys all keep working.

**What keeps working unchanged:**

- The env keys `CAPTCHA_SECRET` and `CAPTCHA_SITEKEY`. If both `CAPTCHA_*` and `HCAPTCHA_*` are set, `HCAPTCHA_*` wins, so a half-migrated `.env` behaves predictably rather than randomly.
- A previously published `config/captcha.php` — its `secret`, `sitekey`, `options.lang`, and `attributes` values are adopted for any `hcaptcha.*` key that is not itself set. A configured `hcaptcha.*` value always wins over the legacy config.
- The `Captcha` facade, with all eight methods from the old package: `display()`, `displayMultiple()`, `displayJs()`, `multiple()`, `setOptions()`, `verify()`, `getWidgetIdName()`, `getJsVariableName()`.
- `Captcha::verify($response, $clientIp = null, $options = [])` returns a plain **bool**, exactly like before. To see *why* a token failed, call the native `HCaptcha::verify()` instead, which returns a `VerificationResult`.
- The `captcha` string validation rule (`'h-captcha-response' => 'captcha'`).
- The `Form::captcha()` macro, registered only when a `form` binding exists in the container.

**What is different, and why:**

- `http_client` in the old config is **ignored**. It named a Guzzle-based HTTP client class, and replacing Guzzle with Laravel's own `Http` client is the entire reason this package exists — see [Why this exists](#why-this-exists). A configured `http_client` logs a warning and is otherwise skipped.
- The old package's config defaults — the literal strings `default_sitekey` and `default_secret` — are treated as **not configured**, and raise `MissingSitekeyException` / `MissingSecretException` instead of being sent to hCaptcha. This is deliberate: those literals meant every verification silently failed with no indication why, which is exactly the failure mode this package is built to avoid.
- `multiple` mode needs no special handling. Every widget already renders in explicit mode and the bootstrap script renders every container on the page, so several widgets already work side by side. `displayMultiple()` still exists so old calls do not break, but it returns an empty string.
- `verify()` no longer swallows every failure into a bare `false` with no explanation. The native layer distinguishes missing, expired, unavailable, and failed tokens, and fails **closed** on a transport error unless you opt into `HCAPTCHA_FAIL_OPEN=true`.

### The hostname check — the most likely migration surprise

`hostnames` is **on by default** in this package, derived from `APP_URL`. If your form is served from a different host than `APP_URL` (a staging domain, a second brand, a proxy), set `HCAPTCHA_HOSTNAMES` to a comma-separated list of the hostnames that should be accepted — otherwise genuine submissions get rejected with `hostname-mismatch`. See [Your sitekey is public](#your-sitekey-is-public--this-is-why-hostnames-matters) for why this check exists at all. A hostname hCaptcha reports as missing or `not-provided` passes with a warning unless `HCAPTCHA_HOSTNAMES_STRICT` is set. An install that relied on 1.x rejecting an unreported hostname should set `HCAPTCHA_HOSTNAMES_STRICT=true`; either way, the authoritative origin control is the domain allowlist on the sitekey in the hCaptcha dashboard, not this check.

### Recommended follow-up

Once the swap is verified, consider:

- Switching to `<x-hcaptcha />` and the `HCaptcha` rule object for better per-outcome error messages.
- Moving `.env` keys from `CAPTCHA_*` to `HCAPTCHA_*`.
- Deleting `config/captcha.php` once nothing reads it.
- Adding `throttle` to the route the captcha guards — see [Rate limiting](#rate-limiting).

## Configuration

Every key in `config/hcaptcha.php`:

| Key | Env var | Default |
| --- | --- | --- |
| `sitekey` | `HCAPTCHA_SITEKEY` | `null` |
| `secret` | `HCAPTCHA_SECRET` | `null` |
| `endpoint` | `HCAPTCHA_ENDPOINT` | `https://api.hcaptcha.com/siteverify` |
| `timeout` | `HCAPTCHA_TIMEOUT` | `10` |
| `retries` | `HCAPTCHA_RETRIES` | `0` |
| `max_token_length` | `HCAPTCHA_MAX_TOKEN_LENGTH` | `8192` |
| `fail_open` | `HCAPTCHA_FAIL_OPEN` | `false` |
| `send_sitekey` | `HCAPTCHA_SEND_SITEKEY` | `true` |
| `hostnames` | `HCAPTCHA_HOSTNAMES` | host of `APP_URL` |
| `hostnames_strict` | `HCAPTCHA_HOSTNAMES_STRICT` | `false` |
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

`sitekey` and `secret` both default to `null` on purpose: a missing secret throws (`MissingSecretException`) rather than silently rejecting every visitor, and a missing sitekey throws when a widget or field asks for one (`MissingSitekeyException`), or degrades to rendering nothing when the caller checks `HCaptcha::configured()` first.

`retries` counts additional attempts after the first and defaults to `0`.

`max_token_length` rejects an oversized token without making an HTTP call. hCaptcha tokens run a few hundred to a few thousand characters, so without a cap an unauthenticated request could have the package proxy a huge body to hCaptcha while holding a PHP worker for the whole timeout.

### Your sitekey is public — this is why `hostnames` matters

**Your sitekey is public. It is sitting in your page's HTML.** An attacker can embed *your* sitekey on *their own* page, solve the challenge there themselves — or buy a solved token from a captcha farm — and post that genuine token to your form. `siteverify` answers `success: true`, because the token really was solved against your sitekey. `send_sitekey` cannot catch this: the sitekey matches, so there is nothing to flag.

Two layers limit that attack, and you want both.

1. **The domain allowlist on the sitekey in the hCaptcha dashboard.** This is the authoritative one, and it is *off by default* for new sitekeys. Turn it on.
2. **This package's `hostnames` check**, on by default and derived from `APP_URL`. hCaptcha documents the reported hostname as browser-derived and "not suitable for authentication", and says it may come back as `not-provided` under load. Treat this check as a policy that catches careless misuse, not as proof of origin.

Set `HCAPTCHA_HOSTNAMES` to a comma-separated list for multi-domain installs. Setting it to an empty string disables the check, which is logged as an `error` once per process. A response whose hostname is missing or `not-provided` passes with a `warning`; set `HCAPTCHA_HOSTNAMES_STRICT=true` to reject those instead, with `rejectedBy: hostname-unknown`.

`max_score` is a **risk** score, the inverse of reCAPTCHA v3: higher means more bot-like, so this is a ceiling, not a floor, and it only applies to Enterprise accounts that return a `score` at all.

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

The middleware also accepts an optional second parameter, the sitekey the widget was rendered with (`hcaptcha:h-captcha-response,<sitekey>`), so it shares a verdict with a rule constructed with the same `sitekey:`.

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

When no usable sitekey resolves the field renders no widget (a debug-only notice, like the Blade component) but stays in validation, so the form still fails closed. Widget ids are scoped to the Livewire component, so two forms with the same state path on one page do not collide.

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

2.0.0 changes behaviour in six places. Each is a correctness fix; the common case of one form guarded by the rule and/or the middleware needs no code change, but a Livewire component should delete its manual `core45HCaptcha:reset` dispatch and a Livewire route should drop the middleware.

- **`VerificationResult::success` is now hCaptcha's verdict only.** Read `accepted` (or call `passed()`) for the final answer. In 1.x a local rejection overwrote `success` with `false`; now it leaves `success` as `true` and sets `accepted: false`. Anything that branched on `->success` should branch on `->passed()`.
- **The audit table has a new `accepted` column.** Run `php artisan migrate`. If you published the migrations, publish again with `php artisan vendor:publish --tag=hcaptcha-migrations`. The `failed()` model scope now filters on `accepted`.
- **Memoization is scoped to the executing Livewire component.** Two components validating the same property name in one batched request no longer share a verdict. The middleware and the rule on the same field in a plain form still collapse to one call. If you called `HCaptcha::verify($token, $ip, 'some-scope')` with your own scope string, that still works and now means "field".
- **Stacking the middleware on a Livewire update route no longer shares a verdict with the rule.** The rule scopes by the executing component; the middleware cannot, so the second check is told `already-seen-response`. Guard a Livewire form with the rule alone, which was always the documented setup.
- **Error codes are hCaptcha's.** `tokenAlreadyUsed()` now matches `already-seen-response`; `token-already-used` is kept as an alias. `expired-input-response` reports the expired message; `invalid-input-response` no longer does. `isConfigurationError()` recognises `sitekey-secret-mismatch`, `bad-request` and the dummy-passcode codes and no longer lists codes hCaptcha never returns.
- **An install that relied on 1.x rejecting an unreported hostname should set `HCAPTCHA_HOSTNAMES_STRICT=true`.** A missing or `not-provided` hostname now passes with a warning by default; the authoritative origin control is the domain allowlist on the sitekey in the hCaptcha dashboard, not this check.

Two defaults changed without changing behaviour: `retries` now counts *additional* attempts and defaults to `0` (1.x's default of `1` also made one attempt), and the "hostname check inactive" error is logged once per process rather than once per verification. New opt-ins: `hostnames_strict` and `logging.log_oversized_token`, both `false`.

- **Published views:** if you published `hcaptcha::script` or `hcaptcha::widget` in 1.x, re-publish them. The bootstrap now lives in `resources/js/bootstrap.js` and the widget view emits a status element and `data-hcaptcha-field`.
- **Widget ids are deterministic** (`hcaptcha-page-N`, `hcaptcha-<livewire-id>-N`) instead of random; selectors that relied on the `hcaptcha-` prefix still match.
- **`data-hcaptcha-model` is no longer emitted.**

## Audit trail

Enable with `HCAPTCHA_LOGGING=true` (or `hcaptcha.logging.enabled`) and run the published migration. Every verification attempt — success or failure — writes one row to `hcaptcha_verifications` (configurable table/connection) via `Core45\HCaptcha\Support\VerificationLogger`, which never lets a logging failure fail the verification itself.

Stored: `success`, `accepted`, `token_hash` (SHA-256 of the token), `hostname`, `challenge_ts`, `score`, `error_codes` (JSON), `rejected_by`, plus `ip` / `user_agent` / `url` when their respective PII toggles are on, and timestamps.

**Deliberately not stored: the raw token.** It is a single-use credential — by the time it could be logged it is already spent, so keeping it would be a liability with no benefit. Only its SHA-256 hash is kept, which is enough to correlate a replay without being able to replay it yourself.

The PII toggles (`logging.store_ip`, `logging.store_user_agent`, `logging.store_url`) all default to `false`. When `store_url` is enabled it records the request path **without the query string** — a query string routinely carries signed-URL signatures, password-reset tokens, and email addresses, none of which belong in a 90-day audit table. Enable any of these only with a lawful basis.

`logging.log_missing_token` also defaults to `false`. A submission with no token at all costs an attacker nothing and never reaches hCaptcha, so recording it by default would let anyone inflate the audit table with free, unauthenticated POSTs. Turn it on only alongside route throttling.

`Core45\HCaptcha\Models\HCaptchaVerification` ships three scopes: `scopeFailed()` (`where('accepted', false)`), `scopeRejectedLocally()` (`whereNotNull('rejected_by')`, a genuine token a local assertion turned down), and `scopeForToken(string $tokenHash)` (attempts sharing a token hash — the fingerprint of a replay).

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

hCaptcha needs a few directives allowed. `HCaptchaManager::cspDirectives()` (also `HCaptcha::cspDirectives()` on the facade) returns them, so you can feed them into whatever builds your policy header rather than retyping the origins:

```php
HCaptchaManager::cspDirectives();
// [
//     'script-src'  => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
//     'frame-src'   => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
//     'style-src'   => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
//     'connect-src' => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
// ]
```

The wildcard subdomain is deliberate: hCaptcha rotates its asset subdomain by region and over time, so pinning a specific one will eventually break.

If your `script-src` also uses a nonce (no `'unsafe-inline'`), register a resolver so the widget's two `<script>` tags carry it:

```php
use Core45\HCaptcha\HCaptchaManager;

HCaptchaManager::nonceUsing(fn (): ?string => request()->attributes->get('csp-nonce'));
```

With no resolver registered, the package falls back to Laravel's own Vite nonce (`Vite::useCspNonce()`) if one was set for the request, and otherwise emits no `nonce` attribute at all.

**Limitation for widgets rendered inside a Livewire update.** A widget whose first appearance on the page is a plain Livewire component update — a modal opened after mount, for instance — is picked up by a `@script` fallback, because a `<script>` tag delivered through Livewire's DOM patch never executes. Livewire evaluates the content of `@script` through a function constructor, and no nonce can authorise that. Under a strict policy without `'unsafe-eval'`, that fallback cannot run, and the widget will not render in that case. To support that case your directive needs to read:

```
script-src 'self' 'nonce-<your-nonce>' 'unsafe-eval'
```

Adding `'unsafe-eval'` is a real, material weakening of the policy: it re-enables `eval()`, `Function()` and similar dynamic evaluation for the *entire page*, not narrowly for this package's fallback. Decide with that cost in view, not as a box to tick. This requirement comes from Livewire itself, not from hCaptcha — this package's own scripts never need `'unsafe-eval'`, so a page that never renders the widget for the first time inside a Livewire update (full loads and `wire:navigate` transitions only) does not need it either. Note this is scoped to this package: a Livewire and Alpine page in general commonly needs `'unsafe-eval'` regardless, because Alpine's own expression evaluation also requires it unless you build Alpine's CSP-compatible variant.

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

Browser tests in a consuming app can point `hcaptcha.script.url` at the package's stub, `resources/js/fake-api.js` (served by a route of your own), which implements `render`, `execute`, `reset` and `getResponse` without the network and exposes `window.__fakeHCaptcha`. Phase 4's `HCaptcha::fake()` wires this up for you.

## Versioning & License

Follows [Semantic Versioning](https://semver.org/). Licensed under the [MIT License](LICENSE.md).
